<?php

namespace Modules\Shares\Tests;

use App\Models\BasSettlement;
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
use Modules\Shares\Models\FrankingAccountEntry;
use Modules\Shares\Services\FrankingService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The BAS settlement franking hook is the Shares module's contribution
 * to a core flow: settling the income tax types credits or debits the
 * notional franking account in the same transaction, and reversal
 * mirrors it back out. GST and PAYG withholding are gated out. Kept
 * with the module because both sides move together.
 */
class BasFrankingHookTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected Account $gstPayable; // 2200

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

        $this->seed(IFRSSeeder::class);

        $this->entity = IfrsPosting::resolveEntity();
        $this->gstPayable = $this->account(2200);
        $this->paygWithholding = $this->account(2210);
        $this->incomeTaxPayable = $this->account(2240);
        $this->bank = $this->account(320);
        $this->service = app(BasSettlementService::class);
    }

    protected function account(int $code): Account
    {
        return Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();
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

    /**
     * Post Dr $debitCode / Cr $creditCode — the same legs the payment
     * postings write to the tax accounts, without the subledger.
     */
    protected function postJournal(int $debitCode, int $creditCode, float $amount, $date, string $reference): void
    {
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate(Carbon::parse($date), $this->entity),
            'account_id' => $this->account($debitCode)->id,
            'credited' => false,
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'narration' => "Franking hook fixture {$reference}",
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

    public function test_income_tax_settlements_credit_the_franking_account(): void
    {
        $this->postJournal(320, 2240, 1000, now(), 'ASSESSED');

        $settlement = $this->settle(['type' => BasSettlement::TYPE_INCOME_TAX]);

        $entry = FrankingAccountEntry::query()->sole();
        $this->assertSame(FrankingAccountEntry::TYPE_TAX_PAYMENT, $entry->entry_type);
        $this->assertEqualsWithDelta(1000.0, (float) $entry->credit_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $entry->debit_amount, 0.001);
        // Franking credits arise on payment — the bank date, not as_at.
        $this->assertSame($settlement->settled_at->toDateString(), $entry->entry_date->toDateString());
        $this->assertFalse($entry->is_estimated);
        $this->assertSame($settlement->ifrs_transaction_id, $entry->ifrs_transaction_id);
        $this->assertEqualsWithDelta(1000.0, FrankingService::balance(), 0.001);
    }

    public function test_payg_instalment_settlements_credit_the_franking_account(): void
    {
        $this->postJournal(320, 2240, 1500, now(), 'INSTALMENT');

        $this->settle(['type' => BasSettlement::TYPE_PAYG_INSTALMENT]);

        $entry = FrankingAccountEntry::query()->sole();
        $this->assertSame(FrankingAccountEntry::TYPE_TAX_PAYMENT, $entry->entry_type);
        $this->assertEqualsWithDelta(1500.0, FrankingService::balance(), 0.001);
    }

    public function test_gst_and_payg_withholding_settlements_never_touch_franking(): void
    {
        $this->collect(1000);
        $this->postJournal(320, 2210, 800, now(), 'WITHHELD');

        $this->settle();
        $this->settle(['type' => BasSettlement::TYPE_PAYG]);

        $this->assertDatabaseCount('franking_account_entries', 0);
        $this->assertEqualsWithDelta(0.0, FrankingService::balance(), 0.001);
    }

    public function test_an_income_tax_refund_debits_the_franking_account(): void
    {
        $this->postJournal(2240, 320, 300, now(), 'OVERPAID');

        $this->settle(['type' => BasSettlement::TYPE_INCOME_TAX]);

        $entry = FrankingAccountEntry::query()->sole();
        $this->assertSame(FrankingAccountEntry::TYPE_REFUND_RECEIVED, $entry->entry_type);
        $this->assertEqualsWithDelta(300.0, (float) $entry->debit_amount, 0.001);
        $this->assertEqualsWithDelta(-300.0, FrankingService::balance(), 0.001);
    }

    public function test_reversing_a_settlement_mirrors_out_its_franking_entry(): void
    {
        $this->postJournal(320, 2240, 1000, now(), 'ASSESSED');
        $settlement = $this->settle(['type' => BasSettlement::TYPE_INCOME_TAX]);

        $this->assertEqualsWithDelta(1000.0, FrankingService::balance(), 0.001);

        $this->service->reverse($settlement);

        $entries = FrankingAccountEntry::query()->orderBy('id')->get();
        $this->assertCount(2, $entries);
        [$credit, $mirror] = $entries->all();
        $this->assertSame(FrankingAccountEntry::TYPE_TAX_PAYMENT, $mirror->entry_type);
        $this->assertEqualsWithDelta(1000.0, (float) $mirror->debit_amount, 0.001);
        $this->assertSame($credit->entry_date->toDateString(), $mirror->entry_date->toDateString());
        $this->assertEqualsWithDelta(0.0, FrankingService::balance(), 0.001);
    }

    public function test_an_exactly_offset_income_tax_position_has_nothing_to_settle(): void
    {
        // The single liability account plays both netting roles, so an
        // accrued payable exactly matched by an overpayment reads as a
        // zero balance — settle() refuses, which is why a zero-bank
        // income tax settlement (and a franking entry for one) cannot
        // exist.
        $this->postJournal(320, 2240, 500, now(), 'ASSESSED');
        $this->postJournal(2240, 320, 500, now(), 'OVERPAID');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no unsettled income tax');

        $this->settle(['type' => BasSettlement::TYPE_INCOME_TAX]);
    }
}
