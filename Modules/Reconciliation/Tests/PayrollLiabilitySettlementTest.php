<?php

namespace Modules\Reconciliation\Tests;

use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\OpeningBalances;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Models\ReconciliationHistory;
use Modules\Reconciliation\Services\PayrollLiabilityService;
use Modules\Reconciliation\Services\ReconciliationService;
use Tests\TestCase;

/**
 * Settling a payroll liability from the bank feed — the payment that
 * leaves the bank after a pay run posted its accruals. The run's
 * PAYROLL-*-SUP/ACC journals credit the payables but never touch the
 * bank, so without this path the super/PAYG bank lines had no book
 * movement to reconcile against. Settling posts Dr payable / Cr bank
 * dated the line's date, matches the line to the bank leg, and the
 * liability plus the bank-vs-books gap close together. Net pay is
 * deliberately not settleable here — the run's PAY journal already
 * recorded it leaving the bank.
 */
class PayrollLiabilitySettlementTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected Account $operating;

    protected Account $paygPayable;

    protected Account $superPayable;

    protected Account $wagesPayable;

    protected PayrollLiabilityService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-05 09:00'));

        $this->entity = $this->seedIfrs();
        $this->operating = $this->account(320);
        $this->paygPayable = $this->account(2210);
        $this->superPayable = $this->account(2220);
        $this->wagesPayable = $this->account(2235);
        $this->service = app(PayrollLiabilityService::class);
        $this->user = User::factory()->create(['entity_id' => $this->entity->id]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    protected function seedIfrs(): Entity
    {
        $entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 7,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $entity->id,
        ]);
        $entity->update(['currency_id' => $currency->id]);
        $entity->refresh();

        ReportingPeriod::create([
            'period_count' => 1,
            'calendar_year' => 2026,
            'status' => ReportingPeriod::OPEN,
            'entity_id' => $entity->id,
        ]);

        foreach ([
            ['Operating Account', Account::BANK, 320],
            ['PAYG Withholding Payable', Account::CURRENT_LIABILITY, 2210],
            ['Superannuation Payable', Account::CURRENT_LIABILITY, 2220],
            ['Wages Payable', Account::CURRENT_LIABILITY, 2235],
        ] as [$name, $type, $code]) {
            Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $entity->id,
            ]);
        }

        return $entity;
    }

    protected function account(int $code): Account
    {
        return Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();
    }

    protected function balance(Account $account): float
    {
        return OpeningBalances::balanceAt($account, $this->entity, now());
    }

    protected function bankLine(array $attributes = []): BankTransaction
    {
        return BankTransaction::create(array_merge([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-'.uniqid(),
            'description' => 'Sent money to SAMPLE SUPERANNUATION FUND',
            'amount' => -456.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_DEBIT,
            'transaction_date' => Carbon::parse('2026-10-01'),
            'status' => BankTransaction::STATUS_PENDING,
        ], $attributes));
    }

    public function test_settling_the_super_payment_clears_the_payable_and_matches(): void
    {
        $line = $this->bankLine();

        $this->service->settle($line, $this->operating->id, $this->superPayable->id, 'September quarter super');

        $line->refresh();
        $this->assertTrue($line->isMatched());
        $this->assertSame('ledger', $line->matched_transaction_type);

        // Dr Super Payable / Cr Bank: the liability clears in full and
        // the bank movement the feed described now exists.
        $this->assertEqualsWithDelta(456.0, $this->balance($this->superPayable), 0.001);
        $this->assertEqualsWithDelta(-456.0, $this->balance($this->operating), 0.001);

        // The bank leg is the match target — a bank-account ledger row.
        $leg = Ledger::find($line->matched_transaction_id);
        $this->assertSame($this->operating->id, $leg->post_account);

        $check = app(ReconciliationService::class)->bankVsBooks();
        $this->assertEqualsWithDelta(-456.0, $check['books_total'], 0.001);
        $this->assertEqualsWithDelta(-456.0, $check['actual'], 0.001);
        $this->assertEqualsWithDelta(0.0, $check['gap'], 0.001);

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $line->id,
            'action' => ReconciliationHistory::ACTION_MANUAL_MATCH,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
    }

    public function test_settling_the_payg_payment_posts_against_payg_withholding(): void
    {
        $line = $this->bankLine([
            'description' => 'Sent money to AUSTRALIAN TAXATION OFFICE',
            'amount' => -854.00,
        ]);

        $this->service->settle($line, $this->operating->id, $this->paygPayable->id);

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(854.0, $this->balance($this->paygPayable), 0.001);
        $this->assertEqualsWithDelta(-854.0, $this->balance($this->operating), 0.001);
    }

    public function test_the_settleable_payables_are_the_statutory_accounts_only(): void
    {
        $payables = $this->service->settlablePayables($this->entity);

        // PAYG and super payable — never wages payable (the PAY journal
        // already records the net leaving the bank).
        $this->assertSame(
            [$this->paygPayable->id, $this->superPayable->id],
            $payables->pluck('id')->values()->all(),
        );
    }

    public function test_refusals(): void
    {
        $credit = BankTransaction::create([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-'.uniqid(),
            'description' => 'Refund',
            'amount' => 100.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::parse('2026-10-01'),
            'status' => BankTransaction::STATUS_PENDING,
        ]);
        try {
            $this->service->settle($credit, $this->operating->id, $this->paygPayable->id);
            $this->fail('A money-in line should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('money-out', $e->getMessage());
        }

        $foreign = $this->bankLine(['currency' => 'USD']);
        try {
            $this->service->settle($foreign, $this->operating->id, $this->superPayable->id);
            $this->fail('A non-entity-currency line should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('USD', $e->getMessage());
        }

        $matched = $this->bankLine();
        $matched->update(['status' => BankTransaction::STATUS_MATCHED]);
        try {
            $this->service->settle($matched, $this->operating->id, $this->superPayable->id);
            $this->fail('A non-pending line should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('pending', $e->getMessage());
        }

        // A crafted id for an account outside the configured payables
        // (wages payable is on the chart but not settleable) must not
        // post through this path.
        try {
            $this->service->settle($this->bankLine(), $this->operating->id, $this->wagesPayable->id);
            $this->fail('A non-settleable account should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('payroll liability account', $e->getMessage());
        }

        FiscalPeriod::create([
            'name' => 'Locked month',
            'year' => 2026,
            'period_type' => FiscalPeriod::TYPE_MONTHLY,
            'start_date' => Carbon::parse('2026-10-01'),
            'end_date' => Carbon::parse('2026-10-31'),
            'is_locked' => true,
            'locked_at' => now(),
        ]);
        try {
            $this->service->settle($this->bankLine(), $this->operating->id, $this->superPayable->id);
            $this->fail('A locked period should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('locked period', $e->getMessage());
        }

        // Every refusal posted nothing and matched nothing.
        $this->assertEqualsWithDelta(0.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->superPayable), 0.001);
        $this->assertSame(0, BankTransaction::query()->whereNotNull('matched_transaction_id')->where('matched_transaction_type', 'ledger')->count());
    }

    public function test_an_unmatched_line_settles_again_without_double_posting(): void
    {
        $line = $this->bankLine();
        $this->service->settle($line, $this->operating->id, $this->superPayable->id);
        $this->assertEqualsWithDelta(456.0, $this->balance($this->superPayable), 0.001);

        // Unmatch returns the line to pending; the journal — the real
        // payment — stays. Settling again must re-match it, not post a
        // second journal.
        app(ReconciliationService::class)->unlinkTransaction($line->refresh());
        $this->assertSame(BankTransaction::STATUS_PENDING, $line->refresh()->status);

        $this->service->settle($line->refresh(), $this->operating->id, $this->superPayable->id);

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(456.0, $this->balance($this->superPayable), 0.001);
        $this->assertEqualsWithDelta(-456.0, $this->balance($this->operating), 0.001);
    }

    public function test_the_match_screen_offers_the_right_card_per_direction(): void
    {
        $debit = $this->bankLine();

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $debit))
            ->assertOk()
            ->assertSee(__('reconciliation.settlement.title'), false)
            ->assertDontSee(__('reconciliation.settlement.refund_title'), false)
            ->assertSee('2220 — Superannuation Payable');

        // Money-in lines get the refund variant of the card.
        $credit = BankTransaction::create([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-'.uniqid(),
            'description' => 'Refund',
            'amount' => 100.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::parse('2026-10-01'),
            'status' => BankTransaction::STATUS_PENDING,
        ]);
        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $credit))
            ->assertOk()
            ->assertSee(__('reconciliation.settlement.refund_title'), false)
            ->assertDontSee(__('reconciliation.settlement.title'), false);
    }

    public function test_refunding_an_over_remittance_restores_the_payable_and_matches(): void
    {
        $line = BankTransaction::create([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-'.uniqid(),
            'description' => 'Received money from AUSTRALIAN TAXATION OFFICE',
            'amount' => 1000.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::parse('2026-10-01'),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $this->service->refund($line, $this->operating->id, $this->paygPayable->id, 'over-remitted Q1');

        $line->refresh();
        $this->assertTrue($line->isMatched());
        $this->assertSame('ledger', $line->matched_transaction_type);

        // Dr Bank / Cr PAYG payable: the over-remittance comes back and
        // the liability is restored.
        $this->assertEqualsWithDelta(1000.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(-1000.0, $this->balance($this->paygPayable), 0.001);

        $leg = Ledger::find($line->matched_transaction_id);
        $this->assertSame($this->operating->id, $leg->post_account);

        $check = app(ReconciliationService::class)->bankVsBooks();
        $this->assertEqualsWithDelta(0.0, $check['gap'], 0.001);

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $line->id,
            'action' => ReconciliationHistory::ACTION_MANUAL_MATCH,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
    }

    public function test_refund_refusals(): void
    {
        // A money-out line is a settlement, never a refund.
        try {
            $this->service->refund($this->bankLine(), $this->operating->id, $this->paygPayable->id);
            $this->fail('A money-out line should refuse the refund path.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('money-in', $e->getMessage());
        }

        // And the mirror: a money-in line cannot settle.
        $credit = BankTransaction::create([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-'.uniqid(),
            'description' => 'Refund',
            'amount' => 100.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::parse('2026-10-01'),
            'status' => BankTransaction::STATUS_PENDING,
        ]);
        try {
            $this->service->settle($credit, $this->operating->id, $this->paygPayable->id);
            $this->fail('A money-in line should refuse the settle path.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('money-out', $e->getMessage());
        }

        // A crafted id outside the configured payables never posts.
        try {
            $this->service->refund($credit, $this->operating->id, $this->wagesPayable->id);
            $this->fail('A non-settleable account should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('payroll liability account', $e->getMessage());
        }

        $this->assertEqualsWithDelta(0.0, $this->balance($this->operating), 0.001);
    }

    public function test_an_unmatched_refund_line_records_again_without_double_posting(): void
    {
        $line = BankTransaction::create([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-'.uniqid(),
            'description' => 'Refund',
            'amount' => 500.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::parse('2026-10-01'),
            'status' => BankTransaction::STATUS_PENDING,
        ]);
        $this->service->refund($line, $this->operating->id, $this->superPayable->id);

        app(ReconciliationService::class)->unlinkTransaction($line->refresh());
        $this->assertSame(BankTransaction::STATUS_PENDING, $line->refresh()->status);

        $this->service->refund($line->refresh(), $this->operating->id, $this->superPayable->id);

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(500.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(-500.0, $this->balance($this->superPayable), 0.001);
    }

    public function test_a_refund_posts_via_http(): void
    {
        $line = BankTransaction::create([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-'.uniqid(),
            'description' => 'Received money from AUSTRALIAN TAXATION OFFICE',
            'amount' => 750.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::parse('2026-10-01'),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $this->actingAs($this->user)
            ->post(route('reconciliation.settle-payroll', $line), [
                'bank_account_id' => $this->operating->id,
                'payable_account_id' => $this->paygPayable->id,
                'notes' => 'over-remitted Q1',
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(750.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(-750.0, $this->balance($this->paygPayable), 0.001);
    }

    public function test_the_settlement_posts_via_http(): void
    {
        $line = $this->bankLine();

        $this->actingAs($this->user)
            ->post(route('reconciliation.settle-payroll', $line), [
                'bank_account_id' => $this->operating->id,
                'payable_account_id' => $this->superPayable->id,
                'notes' => 'September quarter super',
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(456.0, $this->balance($this->superPayable), 0.001);
        $this->assertEqualsWithDelta(-456.0, $this->balance($this->operating), 0.001);
    }

    public function test_a_refusal_via_http_flashes_the_error_and_posts_nothing(): void
    {
        $line = $this->bankLine();

        $this->actingAs($this->user)
            ->post(route('reconciliation.settle-payroll', $line), [
                'bank_account_id' => $this->operating->id,
                'payable_account_id' => $this->wagesPayable->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(BankTransaction::STATUS_PENDING, $line->refresh()->status);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->operating), 0.001);
    }

    public function test_an_unexpected_failure_flashes_the_generic_message_not_the_exception(): void
    {
        $line = $this->bankLine();

        $this->mock(PayrollLiabilityService::class, function ($mock) {
            $mock->shouldReceive('settle')
                ->andThrow(new \RuntimeException('internal details from the storage layer'));
        });

        $this->actingAs($this->user)
            ->post(route('reconciliation.settle-payroll', $line), [
                'bank_account_id' => $this->operating->id,
                'payable_account_id' => $this->superPayable->id,
            ])
            ->assertSessionHas('error', __('reconciliation.action_failed'));

        $this->assertSame(BankTransaction::STATUS_PENDING, $line->refresh()->status);
    }
}
