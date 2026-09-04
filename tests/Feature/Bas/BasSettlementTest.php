<?php

namespace Tests\Feature\Bas;

use App\Models\BasSettlement;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\BasSettlementService;
use App\Services\IfrsPosting;
use App\Services\OpeningBalances;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BAS settlements: the balance-based netting that lets one settlement
 * catch up any number of missed quarters (the "lazy" case — even
 * across financial years, since the year close carries the GST
 * accounts), the clearing journal for pay/refund directions, and the
 * guards — nothing to settle, locked bank dates, reversal, role
 * gating.
 */
class BasSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected Account $gstPayable; // 2200

    protected Account $gstReceivable; // 430

    protected Account $paygWithholding; // 2210

    protected Account $incomeTaxPayable; // 2240

    protected Account $bank; // 320

    protected BasSettlementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class); // entity, chart of accounts, GST Vats

        $this->entity = IfrsPosting::resolveEntity();
        $this->gstPayable = $this->account(2200);
        $this->gstReceivable = $this->account(430);
        $this->paygWithholding = $this->account(2210);
        $this->incomeTaxPayable = $this->account(2240);
        $this->bank = $this->account(320);
        $this->service = app(BasSettlementService::class);
    }

    protected function account(int $code): Account
    {
        return Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();
    }

    protected function admin(): User
    {
        return tap(User::factory()->create())->assignRole('admin');
    }

    protected function staff(): User
    {
        return tap(User::factory()->create())->assignRole('staff');
    }

    /**
     * Post Dr $debitCode / Cr $creditCode — the same legs the payment
     * postings write to the GST accounts, without the subledger.
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
            'narration' => "GST fixture {$reference}",
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

    /** Debit-positive balance at "now". */
    protected function balance(Account $account): float
    {
        return OpeningBalances::balanceAt($account, $this->entity, now());
    }

    protected function settle(array $overrides = []): BasSettlement
    {
        return $this->service->settle(array_merge([
            'as_at' => now()->toDateString(),
            'settled_at' => now()->toDateString(),
        ], $overrides));
    }

    /** GST collected (Cr 2200) with its bank debit. */
    protected function collect(float $amount, $date = null, string $reference = 'COLLECT'): void
    {
        $this->postJournal(320, 2200, $amount, $date ?? now(), $reference);
    }

    /** GST paid on purchases (Dr 430) with its bank credit. */
    protected function paid(float $amount, $date = null, string $reference = 'PAID'): void
    {
        $this->postJournal(430, 320, $amount, $date ?? now(), $reference);
    }

    public function test_settlement_page_is_gated_to_admin_or_accountant(): void
    {
        $this->actingAs($this->staff())->get('/bas-settlements')->assertForbidden();

        $this->actingAs(tap(User::factory()->create())->assignRole('accountant'))
            ->get('/bas-settlements')
            ->assertOk();

        $this->actingAs($this->admin())
            ->get('/bas-settlements')
            ->assertOk()
            ->assertSee('Record BAS settlement');
    }

    public function test_staff_cannot_record_a_settlement(): void
    {
        $this->actingAs($this->staff())
            ->post('/bas-settlements', ['as_at' => now()->toDateString(), 'settled_at' => now()->toDateString()])
            ->assertForbidden();

        $this->assertDatabaseCount('bas_settlements', 0);
    }

    public function test_position_nets_the_gst_account_balances(): void
    {
        $this->collect(1000);
        $this->paid(400);

        $position = $this->service->position();

        $this->assertEqualsWithDelta(1000.0, $position['payable'], 0.001);
        $this->assertEqualsWithDelta(400.0, $position['receivable'], 0.001);
        $this->assertEqualsWithDelta(600.0, $position['net'], 0.001);
    }

    public function test_paying_the_ato_clears_both_accounts(): void
    {
        $this->collect(1000);
        $this->paid(400);
        $bankBefore = $this->balance($this->bank);

        $settlement = $this->settle();

        $this->assertSame(BasSettlement::DIRECTION_PAY, $settlement->direction);
        $this->assertEqualsWithDelta(600.0, $settlement->net_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstPayable), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstReceivable), 0.001);
        // Dr Bank 1000 - Cr Bank 400 - Cr Bank 600 (the ATO payment)
        $this->assertEqualsWithDelta($bankBefore - 600.0, $this->balance($this->bank), 0.001);
    }

    public function test_a_net_refund_direction_clears_both_accounts(): void
    {
        $this->collect(300);
        $this->paid(700);
        $bankBefore = $this->balance($this->bank);

        $settlement = $this->settle();

        $this->assertSame(BasSettlement::DIRECTION_REFUND, $settlement->direction);
        $this->assertEqualsWithDelta(400.0, $settlement->bank_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstPayable), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstReceivable), 0.001);
        // The refund lands back in the bank: Dr Bank 400 for the ATO refund
        $this->assertEqualsWithDelta($bankBefore + 400.0, $this->balance($this->bank), 0.001);
    }

    public function test_an_exact_offset_settles_without_a_bank_leg(): void
    {
        $this->collect(500);
        $this->paid(500);
        $bankBefore = $this->balance($this->bank);

        $settlement = $this->settle();

        $this->assertSame(BasSettlement::DIRECTION_PAY, $settlement->direction);
        $this->assertEqualsWithDelta(0.0, $settlement->bank_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstPayable), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstReceivable), 0.001);
        $this->assertEqualsWithDelta($bankBefore, $this->balance($this->bank), 0.001);
    }

    public function test_refuses_when_there_is_nothing_to_settle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no unsettled GST');

        $this->settle();
    }

    public function test_one_settlement_catches_up_quarters_and_a_prior_financial_year(): void
    {
        // The lazy case: GST accrued in a prior financial year (already
        // carried through its year close as opening balances), an earlier
        // quarter of the current year, and the current quarter — all
        // still sitting unsettled on the two accounts.
        $this->collect(2500, now()->subYear()->startOfMonth()->addDays(40), 'FY-AGO'); // prior FY
        $this->paid(900, now()->subMonth()->startOfMonth(), 'LAST-QUARTER');
        $this->collect(1100, now(), 'THIS-QUARTER');

        $settlement = $this->settle();

        $this->assertEqualsWithDelta(3600.0, $settlement->gst_payable, 0.001);
        $this->assertEqualsWithDelta(900.0, $settlement->gst_receivable, 0.001);
        $this->assertEqualsWithDelta(2700.0, $settlement->net_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstPayable), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstReceivable), 0.001);
    }

    public function test_payg_withholding_settles_its_liability_account(): void
    {
        // Withholding accrued (Cr 2210) with its bank side — the same
        // single-liability netting, no receivable counterpart.
        $this->postJournal(320, 2210, 800, now(), 'WITHHELD');
        $bankBefore = $this->balance($this->bank);

        $settlement = $this->settle(['type' => BasSettlement::TYPE_PAYG]);

        $this->assertSame(BasSettlement::TYPE_PAYG, $settlement->type);
        $this->assertSame(BasSettlement::DIRECTION_PAY, $settlement->direction);
        $this->assertEqualsWithDelta(800.0, $settlement->net_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->paygWithholding), 0.001);
        $this->assertEqualsWithDelta($bankBefore - 800.0, $this->balance($this->bank), 0.001);
    }

    public function test_an_income_tax_overpayment_settles_as_a_refund(): void
    {
        // A debit balance on 2240 is an overpayment — the single account
        // plays the receivable role and the settlement refunds it.
        $this->postJournal(2240, 320, 300, now(), 'OVERPAID');
        $bankBefore = $this->balance($this->bank);

        $settlement = $this->settle(['type' => BasSettlement::TYPE_INCOME_TAX]);

        $this->assertSame(BasSettlement::TYPE_INCOME_TAX, $settlement->type);
        $this->assertSame(BasSettlement::DIRECTION_REFUND, $settlement->direction);
        $this->assertEqualsWithDelta(300.0, $settlement->bank_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->incomeTaxPayable), 0.001);
        $this->assertEqualsWithDelta($bankBefore + 300.0, $this->balance($this->bank), 0.001);
    }

    public function test_positions_cover_every_settlement_type(): void
    {
        $this->collect(1000);
        $this->paid(400);
        $this->postJournal(320, 2210, 800, now(), 'WITHHELD');

        $positions = $this->service->positions();

        $this->assertSame(['gst', 'payg_withholding', 'income_tax'], array_keys($positions));
        $this->assertEqualsWithDelta(600.0, $positions['gst']['net'], 0.001);
        $this->assertEqualsWithDelta(800.0, $positions['payg_withholding']['payable'], 0.001);
        $this->assertEqualsWithDelta(0.0, $positions['income_tax']['net'], 0.001);
    }

    public function test_an_unknown_settlement_type_is_refused(): void
    {
        $this->collect(500);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown settlement type');

        $this->settle(['type' => 'stamp_duty']);
    }

    public function test_a_bank_date_in_a_locked_period_is_refused(): void
    {
        $locked = now()->subMonth()->startOfMonth()->addDays(5);
        FiscalPeriod::create([
            'name' => 'Locked month',
            'year' => $locked->year,
            'period_type' => FiscalPeriod::TYPE_MONTHLY,
            'start_date' => $locked->copy()->startOfMonth(),
            'end_date' => $locked->copy()->endOfMonth(),
            'is_locked' => true,
            'locked_at' => now(),
        ]);

        $this->collect(500);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('locked period');

        $this->settle(['settled_at' => $locked->toDateString()]);
    }

    public function test_recording_a_settlement_from_the_page(): void
    {
        $this->collect(1000);
        $this->paid(400);

        $response = $this->actingAs($this->admin())
            ->post('/bas-settlements', [
                'type' => BasSettlement::TYPE_GST,
                'as_at' => now()->toDateString(),
                'settled_at' => now()->toDateString(),
                'reference' => 'ATO receipt 123',
            ]);

        $response->assertRedirect(route('bas-settlements.index'))->assertSessionHas('success');

        $settlement = BasSettlement::query()->firstOrFail();
        $this->assertSame('ATO receipt 123', $settlement->reference);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstPayable), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstReceivable), 0.001);

        $this->actingAs($this->admin())
            ->get('/bas-settlements')
            ->assertOk()
            ->assertSee('ATO receipt 123');
    }

    public function test_reversing_a_settlement_restores_the_balances(): void
    {
        $this->collect(1000);
        $this->paid(400);
        $settlement = $this->settle();

        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstPayable), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->gstReceivable), 0.001);

        $this->service->reverse($settlement);

        $this->assertTrue($settlement->refresh()->isReversed());
        $this->assertEqualsWithDelta(-1000.0, $this->balance($this->gstPayable), 0.001);
        $this->assertEqualsWithDelta(400.0, $this->balance($this->gstReceivable), 0.001);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already been reversed');

        $this->service->reverse($settlement);
    }

    public function test_quarter_ends_offers_completed_quarters_only(): void
    {
        $ends = $this->service->quarterEnds($this->entity);

        $this->assertNotEmpty($ends);
        foreach ($ends as $quarter) {
            $this->assertTrue($quarter['end']->copy()->endOfDay()->lessThan(now()));
            $this->assertSame($quarter['end']->toDateString(), $quarter['end']->copy()->endOfMonth()->toDateString());
            $this->assertStringContainsString('Q', $quarter['label']);
        }

        // Most recent pick defaults the form — a completed quarter end.
        $this->assertTrue(last($ends)['end']->lessThan(now()));
    }
}
