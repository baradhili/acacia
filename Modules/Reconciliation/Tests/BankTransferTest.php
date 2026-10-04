<?php

namespace Modules\Reconciliation\Tests;

use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\OpeningBalances;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Models\ReconciliationHistory;
use Modules\Reconciliation\Services\BankTransferService;
use Modules\Reconciliation\Services\ReconciliationService;
use Tests\TestCase;

/**
 * Recording a bank line as your own money moving — the movement the
 * payment tiers never model. Between two tracked bank accounts the
 * journal is a Dr/Cr bank pair; in from or out to an account outside
 * the books it is Funds Introduced / Funds Withdrawn equity, never
 * revenue (so GST, BAS and the tax report stay untouched). One bank
 * line, one journal — payment-limit splits each record their own —
 * and the recorded line matches to the journal's bank leg, closing
 * its share of the bank-vs-books gap.
 */
class BankTransferTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected Account $operating;

    protected Account $savings;

    protected BankTransferService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00'));

        $this->entity = $this->seedIfrs();
        $this->operating = $this->account(320);
        $this->savings = $this->account(321);
        $this->service = app(BankTransferService::class);
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
            ['Savings Account', Account::BANK, 321],
            ['Consulting Revenue', Account::OPERATING_REVENUE, 4100],
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
            'description' => 'Test bank movement',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::parse('2026-09-10'),
            'status' => BankTransaction::STATUS_PENDING,
        ], $attributes));
    }

    public function test_external_funds_in_post_and_match(): void
    {
        $line = $this->bankLine(['amount' => 1500]);

        $this->service->record($line, $this->operating->id, null, 'topped up from my other bank');

        $line->refresh();
        $this->assertTrue($line->isMatched());
        $this->assertSame('ledger', $line->matched_transaction_type);

        // Dr Bank / Cr Funds Introduced — an equity injection, never
        // revenue: nothing posts to 4100 and the lazily created
        // account is equity.
        $this->assertEqualsWithDelta(1500.0, $this->balance($this->operating), 0.001);
        $funds = $this->account(BankTransferService::FUNDS_INTRODUCED_CODE);
        $this->assertSame(Account::EQUITY, $funds->account_type);
        $this->assertEqualsWithDelta(-1500.0, $this->balance($funds), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->account(4100)), 0.001);

        // The movement the user actually wanted: cash in bank correct,
        // the gap closed.
        $check = app(ReconciliationService::class)->bankVsBooks();
        $this->assertEqualsWithDelta(1500.0, $check['books_total'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $check['actual'], 0.001);
        $this->assertEqualsWithDelta(0.0, $check['gap'], 0.001);

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $line->id,
            'action' => ReconciliationHistory::ACTION_MANUAL_MATCH,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
    }

    public function test_payment_limit_splits_each_record_their_own_journal(): void
    {
        $first = $this->bankLine(['amount' => 500]);
        $second = $this->bankLine(['amount' => 700]);

        $this->service->record($first, $this->operating->id, null);
        $this->service->record($second, $this->operating->id, null);

        $this->assertTrue($first->refresh()->isMatched());
        $this->assertTrue($second->refresh()->isMatched());
        $this->assertEqualsWithDelta(1200.0, $this->balance($this->operating), 0.001);

        $check = app(ReconciliationService::class)->bankVsBooks();
        $this->assertEqualsWithDelta(0.0, $check['gap'], 0.001);
    }

    public function test_a_transfer_between_tracked_accounts_moves_both_sides(): void
    {
        $line = $this->bankLine(['amount' => 300]);

        $this->service->record($line, $this->operating->id, $this->savings->id);

        // Dr Operating / Cr Savings: cash total unchanged, equity
        // untouched (the funds accounts never got created).
        $this->assertEqualsWithDelta(300.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(-300.0, $this->balance($this->savings), 0.001);
        $this->assertNull(
            Account::where('entity_id', $this->entity->id)->where('code', BankTransferService::FUNDS_INTRODUCED_CODE)->first()
        );

        // Total expected cash is zero — the feed only describes one
        // side of an internal move, so the gap carries the other side;
        // that is the multi-account-feed residual, not a transfer bug.
        $check = app(ReconciliationService::class)->bankVsBooks();
        $this->assertEqualsWithDelta(0.0, $check['books_total'], 0.001);
    }

    public function test_external_funds_out_withdraw(): void
    {
        $line = $this->bankLine(['amount' => -800, 'type' => BankTransaction::TYPE_DEBIT]);

        $this->service->record($line, $this->operating->id, null);

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(-800.0, $this->balance($this->operating), 0.001);
        $withdrawn = $this->account(BankTransferService::FUNDS_WITHDRAWN_CODE);
        $this->assertEqualsWithDelta(800.0, $this->balance($withdrawn), 0.001);
    }

    public function test_refusals(): void
    {
        $foreign = $this->bankLine(['currency' => 'USD']);
        try {
            $this->service->record($foreign, $this->operating->id, null);
            $this->fail('A non-entity-currency line should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('USD', $e->getMessage());
        }

        $matched = $this->bankLine();
        $matched->update(['status' => BankTransaction::STATUS_MATCHED]);
        try {
            $this->service->record($matched, $this->operating->id, null);
            $this->fail('A non-pending line should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('pending', $e->getMessage());
        }

        try {
            $this->service->record($this->bankLine(), $this->operating->id, $this->operating->id);
            $this->fail('Both sides the same account should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('different accounts', $e->getMessage());
        }

        FiscalPeriod::create([
            'name' => 'Locked month',
            'year' => 2026,
            'period_type' => FiscalPeriod::TYPE_MONTHLY,
            'start_date' => Carbon::parse('2026-09-01'),
            'end_date' => Carbon::parse('2026-09-30'),
            'is_locked' => true,
            'locked_at' => now(),
        ]);
        try {
            $this->service->record($this->bankLine(), $this->operating->id, null);
            $this->fail('A locked period should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('locked period', $e->getMessage());
        }

        // Every refusal posted nothing.
        $this->assertEqualsWithDelta(0.0, $this->balance($this->operating), 0.001);
        $this->assertSame(0, BankTransaction::query()->whereNotNull('matched_transaction_id')->where('matched_transaction_type', 'ledger')->count());
    }

    public function test_the_match_screen_offers_the_transfer_card(): void
    {
        $line = $this->bankLine();

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $line))
            ->assertOk()
            ->assertSee(__('reconciliation.transfer.title'), false)
            ->assertSee(__('reconciliation.transfer.external_option'), false)
            ->assertSee('320 — Operating Account');

        $this->actingAs($this->user)
            ->post(route('reconciliation.transfer', $line), [
                'bank_account_id' => $this->operating->id,
                'counterpart_account_id' => '',
                'notes' => 'topped up',
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(1500.0, $this->balance($this->operating), 0.001);
    }
}
