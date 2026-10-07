<?php

namespace Modules\Reconciliation\Tests;

use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\OpeningBalances;
use App\Widgets\PnLTrendWidget;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Models\ReconciliationHistory;
use Modules\Reconciliation\Services\BankChargeService;
use Modules\Reconciliation\Services\ReconciliationService;
use Tests\TestCase;

/**
 * The bank's own charges from the feed: interest earned (money-in —
 * Dr bank / Cr interest income) and fees charged (money-out — Dr bank
 * fees / Cr bank). Both had no book path beyond the transfer card's
 * equity, the wrong class for income and expense. Posting matches the
 * line to the journal's bank leg and flows into every cash figure —
 * the families classify by their counterpart accounts, revenue for
 * interest and expense for fees.
 */
class BankChargeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected Account $operating;

    protected BankChargeService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-06 09:00'));

        $this->entity = $this->seedIfrs();
        $this->operating = $this->account(320);
        $this->service = app(BankChargeService::class);
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

        Account::create([
            'name' => 'Operating Account',
            'account_type' => Account::BANK,
            'code' => 320,
            'currency_id' => $currency->id,
            'entity_id' => $entity->id,
        ]);

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
            'description' => 'Interest paid',
            'amount' => 12.34,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::parse('2026-10-01'),
            'status' => BankTransaction::STATUS_PENDING,
        ], $attributes));
    }

    public function test_interest_earned_posts_income_and_matches(): void
    {
        $line = $this->bankLine(['amount' => 12.34]);

        $this->service->record($line, $this->operating->id, 'October interest');

        $line->refresh();
        $this->assertTrue($line->isMatched());
        $this->assertSame('ledger', $line->matched_transaction_type);

        // Dr Bank / Cr Interest Income — income, never equity.
        $accounts = $this->service->accounts($this->entity);
        $this->assertEqualsWithDelta(12.34, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(-12.34, $this->balance($accounts['interest']), 0.001);
        $this->assertSame(Account::NON_OPERATING_REVENUE, $accounts['interest']->account_type);

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

    public function test_fees_charged_post_expense_and_match(): void
    {
        $line = $this->bankLine([
            'description' => 'Account fee',
            'amount' => -8.50,
            'type' => BankTransaction::TYPE_DEBIT,
        ]);

        $this->service->record($line, $this->operating->id);

        $this->assertTrue($line->refresh()->isMatched());

        // Dr Bank Fees / Cr Bank — an expense, never equity.
        $accounts = $this->service->accounts($this->entity);
        $this->assertEqualsWithDelta(-8.50, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(8.50, $this->balance($accounts['fees']), 0.001);
        $this->assertSame(Account::OPERATING_EXPENSE, $accounts['fees']->account_type);
    }

    public function test_the_charges_classify_in_the_pnl_trend(): void
    {
        $this->service->record($this->bankLine(['amount' => 12.34]), $this->operating->id);
        $this->service->record($this->bankLine([
            'description' => 'Account fee',
            'amount' => -8.50,
            'type' => BankTransaction::TYPE_DEBIT,
            'source_id' => 'WISE-'.uniqid(),
        ]), $this->operating->id);

        $data = app(PnLTrendWidget::class)->run()->getData();
        $october = collect($data['months'])->firstWhere('month', '2026-10');

        // One cash source: interest lands as revenue, fees as
        // expenses, by their counterpart accounts.
        $this->assertEqualsWithDelta(12.34, (float) $october['revenue'], 0.001);
        $this->assertEqualsWithDelta(8.50, (float) $october['expenses'], 0.001);
    }

    public function test_refusals(): void
    {
        $foreign = $this->bankLine(['currency' => 'USD']);
        try {
            $this->service->record($foreign, $this->operating->id);
            $this->fail('A non-entity-currency line should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('USD', $e->getMessage());
        }

        $matched = $this->bankLine();
        $matched->update(['status' => BankTransaction::STATUS_MATCHED]);
        try {
            $this->service->record($matched, $this->operating->id);
            $this->fail('A non-pending line should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('pending', $e->getMessage());
        }

        try {
            $this->service->record($this->bankLine(), 999999);
            $this->fail('An unknown bank account should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('bank account', $e->getMessage());
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
            $this->service->record($this->bankLine(), $this->operating->id);
            $this->fail('A locked period should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('locked period', $e->getMessage());
        }

        // Every refusal posted nothing; the only matched line is the
        // fixture that was already matched.
        $this->assertEqualsWithDelta(0.0, $this->balance($this->operating), 0.001);
        $matchedLines = BankTransaction::query()->where('status', BankTransaction::STATUS_MATCHED)->get();
        $this->assertSame(1, $matchedLines->count());
        $this->assertSame($matched->id, $matchedLines->first()->id);
    }

    public function test_an_unmatched_line_records_again_without_double_posting(): void
    {
        $line = $this->bankLine();
        $this->service->record($line, $this->operating->id);

        app(ReconciliationService::class)->unlinkTransaction($line->refresh());
        $this->assertSame(BankTransaction::STATUS_PENDING, $line->refresh()->status);

        $this->service->record($line->refresh(), $this->operating->id);

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(12.34, $this->balance($this->operating), 0.001);
    }

    public function test_the_card_offers_and_posts_via_http(): void
    {
        $interest = $this->bankLine();

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $interest))
            ->assertOk()
            ->assertSee(__('reconciliation.interest_fees.interest_title'), false);

        $fee = $this->bankLine([
            'description' => 'Account fee',
            'amount' => -8.50,
            'type' => BankTransaction::TYPE_DEBIT,
        ]);
        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $fee))
            ->assertOk()
            ->assertSee(__('reconciliation.interest_fees.fee_title'), false);

        $this->actingAs($this->user)
            ->post(route('reconciliation.bank-charge', $interest), [
                'bank_account_id' => $this->operating->id,
                'notes' => 'October interest',
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $this->assertTrue($interest->refresh()->isMatched());
        $this->assertEqualsWithDelta(12.34, $this->balance($this->operating), 0.001);
    }
}
