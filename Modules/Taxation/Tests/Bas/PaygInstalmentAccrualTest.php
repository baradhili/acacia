<?php

namespace Modules\Taxation\Tests\Bas;

use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\IfrsPosting;
use App\Services\OpeningBalances;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Taxation\Models\BasSettlement;
use Modules\Taxation\Models\PaygInstalmentAccrual;
use Modules\Taxation\Services\BasSettlementService;
use Modules\Taxation\Services\PaygInstalmentService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The quarterly PAYG-I accrual journal: Dr income tax expense / Cr
 * income tax payable, computed from the bas.installment_rate config
 * times the quarter's instalment income (cash-basis revenue through
 * the bank). Covers the income base, the guards (unset rate, future
 * or non-quarter dates, duplicates, locks), reversal + re-accrual,
 * and the hand-off into the payg_instalment BAS settlement.
 */
class PaygInstalmentAccrualTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected Account $revenue; // 4100

    protected Account $incomeTaxExpense; // 8400

    protected Account $incomeTaxPayable; // 2240

    protected Account $bank; // 320

    protected PaygInstalmentService $service;

    protected BasSettlementService $settlements;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class); // entity, chart of accounts, GST Vats

        $this->entity = IfrsPosting::resolveEntity();
        $this->revenue = $this->account(4100);
        $this->incomeTaxExpense = $this->account(8400);
        $this->incomeTaxPayable = $this->account(2240);
        $this->bank = $this->account(320);
        $this->service = app(PaygInstalmentService::class);
        $this->settlements = app(BasSettlementService::class);
    }

    protected function account(int $code): Account
    {
        return Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();
    }

    protected function admin(): User
    {
        return tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('admin');
    }

    protected function staff(): User
    {
        return tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('staff');
    }

    /**
     * Post Dr $debitCode / Cr $creditCode — the same legs client
     * receipts write, without the subledger.
     */
    protected function postJournal(int $debitCode, int $creditCode, float $amount, $date, string $reference): void
    {
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate(Carbon::parse($date), $this->entity),
            'account_id' => $this->account($debitCode)->id,
            'credited' => false, // main debited; the line takes the credit
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'narration' => "PAYGI fixture {$reference}",
            'reference' => $reference,
        ]);

        $line = LineItem::create([
            'account_id' => $this->account($creditCode)->id,
            'amount' => $amount,
            'quantity' => 1,
            'entity_id' => $this->entity->id,
        ]);
        $journal->addLineItem($line);
        $journal->post();
    }

    /** A client receipt's legs: Dr bank / Cr revenue (GST-free). */
    protected function receive(float $amount, $date, string $reference = 'RECEIPT'): void
    {
        $this->postJournal(320, 4100, $amount, $date, $reference);
    }

    /** Debit-positive balance at "now". */
    protected function balance(Account $account): float
    {
        return OpeningBalances::balanceAt($account, $this->entity, now());
    }

    /** The latest completed BAS quarter end. */
    protected function quarterEnd(): Carbon
    {
        return last($this->settlements->quarterEnds($this->entity))['end'];
    }

    protected function accrue(array $overrides = []): PaygInstalmentAccrual
    {
        return $this->service->accrue(array_merge([
            'period_end' => $this->quarterEnd()->toDateString(),
        ], $overrides));
    }

    public function test_the_accrual_debits_expense_and_credits_the_income_tax_account(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $end = $this->quarterEnd();
        $this->receive(8000, $end->copy()->subDays(10), 'IN-QUARTER');
        $bankBefore = $this->balance($this->bank);

        $accrual = $this->accrue();

        $this->assertEqualsWithDelta(8000.0, $accrual->instalment_income, 0.001);
        $this->assertEqualsWithDelta(25.0, $accrual->rate, 0.001);
        $this->assertEqualsWithDelta(2000.0, $accrual->amount, 0.001);
        $this->assertSame($end->toDateString(), $accrual->period_end->toDateString());
        // Dr 8400 / Cr 2240 — no bank movement (that is the settlement).
        $this->assertEqualsWithDelta(2000.0, $this->balance($this->incomeTaxExpense), 0.001);
        $this->assertEqualsWithDelta(-2000.0, $this->balance($this->incomeTaxPayable), 0.001);
        $this->assertEqualsWithDelta($bankBefore, $this->balance($this->bank), 0.001);
    }

    public function test_the_income_base_is_the_quarters_bank_settled_revenue_only(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $end = $this->quarterEnd();
        $older = $this->settlements->quarterEnds($this->entity)[0]['end'];

        $this->receive(8000, $end->copy()->subDays(10), 'IN-QUARTER');
        $this->receive(5000, $older->copy()->subDays(10), 'OTHER-QUARTER');
        // Non-bank-settled revenue (an accrual-style journal) never
        // counts: its main account is not a bank.
        $this->postJournal(5500, 4100, 999, $end->copy()->subDays(5), 'NON-CASH');

        $accrual = $this->accrue();

        $this->assertEqualsWithDelta(8000.0, $accrual->instalment_income, 0.001);
        $this->assertEqualsWithDelta(2000.0, $accrual->amount, 0.001);
    }

    public function test_refuses_without_a_configured_rate(): void
    {
        config(['australian.bas.installment_rate' => null]);
        $this->receive(8000, $this->quarterEnd()->copy()->subDays(10));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('instalment rate is not configured');

        $this->accrue();
    }

    public function test_refuses_a_quarter_that_has_not_ended(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $future = $this->quarterEnd()->copy()->addMonths(3)->endOfMonth();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has not ended yet');

        $this->accrue(['period_end' => $future->toDateString()]);
    }

    public function test_refuses_a_date_that_is_not_a_bas_quarter_end(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $midQuarter = $this->quarterEnd()->copy()->startOfMonth()->addDays(14);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a BAS quarter end');

        $this->accrue(['period_end' => $midQuarter->toDateString()]);
    }

    public function test_refuses_when_there_is_no_instalment_income(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no instalment income');

        $this->accrue();
    }

    public function test_refuses_a_duplicate_accrual_and_allows_re_accrual_after_reversal(): void
    {
        config(['australian.bas.installment_rate' => 25]);
        $end = $this->quarterEnd();
        $this->receive(8000, $end->copy()->subDays(10));

        $this->accrue();

        try {
            $this->accrue();
            $this->fail('The duplicate accrual should have been refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already accrued', $e->getMessage());
        }

        $this->service->reverse(PaygInstalmentAccrual::firstOrFail());

        // The quarter is live again: re-accruing posts a fresh journal
        // (the reversed pair nets to zero behind it).
        $this->accrue();

        $this->assertEqualsWithDelta(2000.0, $this->balance($this->incomeTaxExpense), 0.001);
        $this->assertEqualsWithDelta(-2000.0, $this->balance($this->incomeTaxPayable), 0.001);
        $this->assertSame(2, PaygInstalmentAccrual::count());
    }

    public function test_reversing_restores_the_balances(): void
    {
        config(['australian.bas.installment_rate' => 25]);
        $end = $this->quarterEnd();
        $this->receive(8000, $end->copy()->subDays(10));

        $accrual = $this->accrue();
        $this->service->reverse($accrual);

        $this->assertTrue($accrual->refresh()->isReversed());
        $this->assertEqualsWithDelta(0.0, $this->balance($this->incomeTaxExpense), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->incomeTaxPayable), 0.001);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already been reversed');

        $this->service->reverse($accrual);
    }

    public function test_an_accrual_into_a_locked_period_is_refused(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $end = $this->quarterEnd();
        $this->receive(8000, $end->copy()->subDays(10));

        FiscalPeriod::create([
            'name' => 'Locked quarter-end month',
            'year' => $end->year,
            'period_type' => FiscalPeriod::TYPE_MONTHLY,
            'start_date' => $end->copy()->startOfMonth(),
            'end_date' => $end->copy()->endOfMonth(),
            'is_locked' => true,
            'locked_at' => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('locked period');

        $this->accrue();
    }

    public function test_the_accrual_feeds_the_payg_instalment_settlement(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $end = $this->quarterEnd();
        $this->receive(8000, $end->copy()->subDays(10));

        $this->accrue();
        $bankBefore = $this->balance($this->bank);

        $settlement = $this->settlements->settle([
            'type' => BasSettlement::TYPE_PAYG_INSTALMENT,
            'as_at' => $end->toDateString(),
            'settled_at' => now()->toDateString(),
        ]);

        $this->assertSame(BasSettlement::DIRECTION_PAY, $settlement->direction);
        $this->assertEqualsWithDelta(2000.0, $settlement->net_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->incomeTaxPayable), 0.001);
        $this->assertEqualsWithDelta($bankBefore - 2000.0, $this->balance($this->bank), 0.001);
    }

    public function test_the_estimate_computes_from_the_configured_rate(): void
    {
        config(['australian.bas.installment_rate' => 12.5]);

        $end = $this->quarterEnd();
        $this->receive(8000, $end->copy()->subDays(10));

        $estimate = $this->service->estimate($this->entity, $end);

        $this->assertEqualsWithDelta(12.5, $estimate['rate'], 0.001);
        $this->assertEqualsWithDelta(8000.0, $estimate['income'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $estimate['amount'], 0.001);
        $this->assertFalse($estimate['accrued']);

        // With the rate unset the estimate still renders (the screen's
        // configuration hint), just without an amount.
        config(['australian.bas.installment_rate' => null]);
        $estimate = $this->service->estimate($this->entity, $end);
        $this->assertNull($estimate['rate']);
        $this->assertNull($estimate['amount']);
        $this->assertEqualsWithDelta(8000.0, $estimate['income'], 0.001);
    }

    public function test_an_accrual_covered_by_a_settlement_cannot_be_reversed(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $end = $this->quarterEnd();
        $this->receive(8000, $end->copy()->subDays(10));

        $accrual = $this->accrue();
        $settlement = $this->settlements->settle([
            'type' => BasSettlement::TYPE_PAYG_INSTALMENT,
            'as_at' => $end->toDateString(),
            'settled_at' => now()->toDateString(),
        ]);

        // Reversing only the accrual would strand the ATO payment and
        // leave 2240 debited — a fictitious refundable overpayment.
        try {
            $this->service->reverse($accrual);
            $this->fail('The reversal should have been refused while a settlement covers the accrual.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('reverse that settlement first', $e->getMessage());
        }

        $accrual->refresh();
        $this->assertFalse($accrual->isReversed());
        $this->assertEqualsWithDelta(0.0, $this->balance($this->incomeTaxPayable), 0.001);

        // With the settlement reversed out of the way, the accrual
        // reverses normally.
        $this->settlements->reverse($settlement);
        $this->service->reverse($accrual);

        $this->assertTrue($accrual->refresh()->isReversed());
        $this->assertEqualsWithDelta(0.0, $this->balance($this->incomeTaxExpense), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->incomeTaxPayable), 0.001);
    }

    public function test_a_settlement_recorded_before_a_backdated_accrual_does_not_block_its_reversal(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $ends = $this->settlements->quarterEnds($this->entity);
        $laterEnd = last($ends)['end'];
        $earlierEnd = $ends[count($ends) - 2]['end'];

        // A settlement recorded first, netting an unrelated 2240
        // balance as at a date later than the backdated accrual's
        // quarter end.
        $this->postJournal(320, 2240, 500, $laterEnd->copy()->subDays(5), 'PRIOR-LIAB');
        $settlement = $this->settlements->settle([
            'type' => BasSettlement::TYPE_PAYG_INSTALMENT,
            'as_at' => $laterEnd->toDateString(),
            'settled_at' => now()->toDateString(),
        ]);

        // Then the backdated accrual for the earlier quarter — posted
        // after the settlement, so its balance was never netted by it.
        $this->receive(8000, $earlierEnd->copy()->subDays(10), 'BACKDATED');
        $accrual = $this->accrue(['period_end' => $earlierEnd->toDateString()]);

        // Make the creation order explicit beyond second precision: the
        // settlement predates the accrual by an hour.
        $settlement->forceFill(['created_at' => now()->subHour()])->save();

        $this->service->reverse($accrual);

        $this->assertTrue($accrual->refresh()->isReversed());
        $this->assertEqualsWithDelta(0.0, $this->balance($this->incomeTaxExpense), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->incomeTaxPayable), 0.001);
    }

    public function test_the_rate_is_normalized_to_the_snapshots_precision(): void
    {
        config(['australian.bas.installment_rate' => 12.34567]);

        $end = $this->quarterEnd();
        $this->receive(100000, $end->copy()->subDays(10));

        $accrual = $this->accrue();

        // The stored 4-decimal rate must explain the posted amount
        // exactly: 100,000 × 12.3457% = 12,345.70, not the 12,345.67
        // full-precision arithmetic would journal.
        $this->assertEqualsWithDelta(12.3457, $accrual->rate, 0.00001);
        $this->assertEqualsWithDelta(12345.7, $accrual->amount, 0.001);
        $this->assertEqualsWithDelta(-12345.7, $this->balance($this->incomeTaxPayable), 0.001);
    }

    public function test_a_malformed_paygi_quarter_parameter_falls_back_to_the_latest_quarter(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        // Not a date at all: the card falls back to the latest
        // completed quarter instead of erroring on Carbon::parse().
        $this->actingAs($this->admin())
            ->get('/bas-settlements?paygi_quarter=not-a-date')
            ->assertOk()
            ->assertSee('PAYG instalment accrual');
    }

    public function test_staff_cannot_record_an_accrual(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $this->actingAs($this->staff())
            ->post('/paygi-accruals', ['period_end' => $this->quarterEnd()->toDateString()])
            ->assertForbidden();

        $this->assertDatabaseCount('payg_instalment_accruals', 0);
    }

    public function test_the_screen_shows_the_accrual_card_and_its_configuration_hint(): void
    {
        config(['australian.bas.installment_rate' => null]);

        $this->actingAs($this->admin())
            ->get('/bas-settlements')
            ->assertOk()
            ->assertSee('PAYG instalment accrual')
            ->assertSee('BAS_INSTALLMENT_RATE');

        config(['australian.bas.installment_rate' => 25]);
        $end = $this->quarterEnd();
        $this->receive(8000, $end->copy()->subDays(10));

        $this->actingAs($this->admin())
            ->get('/bas-settlements')
            ->assertOk()
            ->assertSee('$'.number_format(8000, 2))
            ->assertSee('25%')
            ->assertSee('$'.number_format(2000, 2))
            ->assertDontSee('BAS_INSTALLMENT_RATE');
    }

    public function test_recording_and_reversing_an_accrual_from_the_page(): void
    {
        config(['australian.bas.installment_rate' => 25]);

        $end = $this->quarterEnd();
        $this->receive(8000, $end->copy()->subDays(10));

        $this->actingAs($this->admin())
            ->post('/paygi-accruals', [
                'period_end' => $end->toDateString(),
                'notes' => 'ATO T7 rate',
            ])
            ->assertRedirect(route('bas-settlements.index'))
            ->assertSessionHas('success');

        $accrual = PaygInstalmentAccrual::firstOrFail();
        $this->assertSame('ATO T7 rate', $accrual->notes);
        $this->assertEqualsWithDelta(-2000.0, $this->balance($this->incomeTaxPayable), 0.001);

        $this->actingAs($this->admin())
            ->get('/bas-settlements')
            ->assertOk()
            ->assertSee('ATO T7 rate')
            ->assertSee('Already accrued');

        $this->actingAs($this->admin())
            ->post('/paygi-accruals/'.$accrual->id.'/reverse')
            ->assertRedirect(route('bas-settlements.index'))
            ->assertSessionHas('success');

        $this->assertTrue($accrual->refresh()->isReversed());
        $this->assertEqualsWithDelta(0.0, $this->balance($this->incomeTaxPayable), 0.001);
    }
}
