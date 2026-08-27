<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Prepayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BillLifecycleService;
use App\Services\PrepaymentService;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bill removal / payment unapplication with strict ledger handling:
 * deleting or unapplying must reverse exactly the bill's share of each
 * payment posting (Dr Bank / Cr Expense / Cr GST), void payments left
 * with no allocations, and neutralise prepayment schedules — without
 * ever leaving the subledger and the IFRS ledger out of sync.
 */
class BillLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIfrs();

        $this->user = User::factory()->create();
        $this->supplier = Supplier::create(['name' => 'Test Supplier']);
    }

    /**
     * Minimum IFRS chart for supplier-payment posting and reversal:
     * entity + period, bank (320), GST Payable (2200) with the GST 10%
     * Vat, expense accounts (5300/8900) and the prepaid pair
     * (460 asset / 7500 expense). Mirrors PostPaymentsToIfrsTest.
     */
    protected function seedIfrs(): Entity
    {
        $entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 1,
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
            'calendar_year' => (int) date('Y'),
            'status' => ReportingPeriod::OPEN,
            'entity_id' => $entity->id,
        ]);

        $accountData = [
            ['Operating Account', Account::BANK, 320],
            ['GST Payable', Account::CONTROL, 2200],
            ['Travel & Accommodation', Account::OPERATING_EXPENSE, 5300],
            ['Other Expenses', Account::OTHER_EXPENSE, 8900],
            ['Prepaid Subscriptions', Account::CURRENT_ASSET, 460],
            ['Subscriptions & Licenses', Account::OPERATING_EXPENSE, 7500],
        ];
        foreach ($accountData as [$name, $type, $code]) {
            Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $entity->id,
            ]);
        }

        Vat::create([
            'name' => 'GST 10%',
            'code' => 'G',
            'rate' => 10,
            'account_id' => Account::where('code', 2200)->first()->id,
            'entity_id' => $entity->id,
        ]);

        return $entity;
    }

    protected function debitSum(Account $account): float
    {
        return (float) Ledger::where('post_account', $account->id)
            ->where('entry_type', Balance::DEBIT)
            ->sum('amount');
    }

    protected function creditSum(Account $account): float
    {
        return (float) Ledger::where('post_account', $account->id)
            ->where('entry_type', Balance::CREDIT)
            ->sum('amount');
    }

    /** Net balance (debits − credits) posted against the account. */
    protected function netSum(Account $account): float
    {
        return round($this->debitSum($account) - $this->creditSum($account), 2);
    }

    protected function account(int $code): Account
    {
        return Account::where('code', $code)->first();
    }

    protected function makeBill(array $items, array $attributes = []): Bill
    {
        $bill = Bill::create(array_merge([
            'supplier_id' => $this->supplier->id,
        ], $attributes));

        foreach ($items as $item) {
            $bill->items()->create($item);
        }
        $bill->recalculateTotals();
        $bill->markAsOpen();

        return $bill;
    }

    protected function payBill(Bill $bill, float $amount): BillPayment
    {
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $bill->supplier_id,
            'paid_by' => $this->user->id,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, $amount);
        $payment->postToIFRS();
        $payment->refresh();

        return $payment;
    }

    public function test_deleting_unpaid_bill_touches_nothing_in_the_ledger(): void
    {
        $bill = $this->makeBill([
            ['description' => 'Item', 'quantity' => 1, 'unit_price' => 110, 'tax_rate' => 10],
        ]);

        $response = $this->actingAs($this->user)->delete(route('bills.destroy', $bill));

        $response->assertRedirect(route('bills.index'))->assertSessionHas('success');
        $this->assertDatabaseMissing('bills', ['id' => $bill->id]);
        $this->assertEquals(0, DB::table('ifrs_transactions')->count());
    }

    public function test_deleting_paid_bill_voids_payment_and_nets_ledger_to_zero(): void
    {
        $bill = $this->makeBill([
            // $110 GST-inclusive coded to 8900: Dr 8900 $100, Dr GST $10
            ['description' => 'Item', 'quantity' => 1, 'unit_price' => 110, 'tax_rate' => 10,
             'expense_account_id' => $this->account(8900)->id],
        ]);
        $payment = $this->payBill($bill, 110);

        $this->assertEquals(Bill::STATUS_PAID, $bill->fresh()->status);

        $response = $this->actingAs($this->user)->delete(route('bills.destroy', $bill));

        $response->assertRedirect(route('bills.index'))->assertSessionHas('success');
        $this->assertDatabaseMissing('bills', ['id' => $bill->id]);
        $this->assertDatabaseHas('bill_payments', [
            'id' => $payment->id,
            'status' => BillPayment::STATUS_VOID,
        ]);
        $this->assertEquals(0, DB::table('bill_payment_allocations')->count());

        // Original + share reversal net to zero on every account.
        $this->assertEquals(0, $this->netSum($this->account(320)));
        $this->assertEquals(0, $this->netSum($this->account(8900)));
        $this->assertEquals(0, $this->netSum($this->account(2200)));

        $this->assertDatabaseHas('ifrs_transactions', [
            'narration' => "Reversal of {$bill->bill_number} share of supplier payment {$payment->payment_number}",
        ]);
    }

    public function test_deleting_one_bill_of_a_shared_payment_reverses_only_its_share(): void
    {
        // Bill A: $100 GST-free to 8900. Bill B: $110 GST-inclusive to 5300.
        $billA = $this->makeBill([
            ['description' => 'A', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0,
             'expense_account_id' => $this->account(8900)->id],
        ]);
        $billB = $this->makeBill([
            ['description' => 'B', 'quantity' => 1, 'unit_price' => 110, 'tax_rate' => 10,
             'expense_account_id' => $this->account(5300)->id],
        ]);

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'paid_by' => $this->user->id,
            'amount' => 210,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($billA, 100);
        $payment->allocateToBill($billB, 110);
        $payment->postToIFRS();
        $payment->refresh();

        $this->actingAs($this->user)->delete(route('bills.destroy', $billA))
            ->assertRedirect(route('bills.index'));

        $this->assertDatabaseMissing('bills', ['id' => $billA->id]);

        // The payment survives (still allocated to B) and B stays paid.
        $this->assertDatabaseHas('bill_payments', [
            'id' => $payment->id,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('bill_payment_allocations', [
            'bill_payment_id' => $payment->id,
            'bill_id' => $billB->id,
            'amount' => 110,
        ]);
        $this->assertEquals(Bill::STATUS_PAID, $billB->fresh()->status);

        // A's ledger share is fully reversed; B's remains.
        $this->assertEquals(0, $this->netSum($this->account(8900)), 'A\'s expense must net to zero');
        $this->assertEquals(100, $this->netSum($this->account(5300)), 'B\'s net expense (ex GST) stays posted');
        $this->assertEquals(10, $this->netSum($this->account(2200)), 'B\'s GST stays posted');
        $this->assertEquals(-110, $this->netSum($this->account(320)), 'Bank keeps only B\'s outflow');
    }

    public function test_unapplying_exclusive_payment_voids_it_and_makes_bill_editable(): void
    {
        $bill = $this->makeBill([
            ['description' => 'Item', 'quantity' => 1, 'unit_price' => 110, 'tax_rate' => 10,
             'expense_account_id' => $this->account(8900)->id],
        ]);
        $payment = $this->payBill($bill, 110);

        $this->assertFalse($bill->fresh()->canBeEdited(), 'Paid bill must not be editable');

        $response = $this->actingAs($this->user)
            ->post(route('bills.unapplyPayment', [$bill, $payment]));

        $response->assertRedirect(route('bills.show', $bill))->assertSessionHas('success');

        $bill->refresh();
        $this->assertEquals(Bill::STATUS_OPEN, $bill->status);
        $this->assertTrue($bill->canBeEdited(), 'Unpaid bill is editable again');
        $this->assertEquals(BillPayment::STATUS_VOID, $payment->fresh()->status);
        $this->assertEquals(0, DB::table('bill_payment_allocations')->count());

        // The whole posting is reversed by the share reversal.
        $this->assertEquals(0, $this->netSum($this->account(320)));
        $this->assertEquals(0, $this->netSum($this->account(8900)));
        $this->assertEquals(0, $this->netSum($this->account(2200)));
    }

    public function test_unapplying_shared_payment_keeps_it_active_for_other_bills(): void
    {
        $billA = $this->makeBill([
            ['description' => 'A', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0,
             'expense_account_id' => $this->account(8900)->id],
        ]);
        $billB = $this->makeBill([
            ['description' => 'B', 'quantity' => 1, 'unit_price' => 110, 'tax_rate' => 10,
             'expense_account_id' => $this->account(5300)->id],
        ]);

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'paid_by' => $this->user->id,
            'amount' => 210,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($billA, 100);
        $payment->allocateToBill($billB, 110);
        $payment->postToIFRS();
        $payment->refresh();

        $this->actingAs($this->user)->post(route('bills.unapplyPayment', [$billB, $payment]))
            ->assertRedirect(route('bills.show', $billB))->assertSessionHas('success');

        // Payment stays completed with A's allocation intact.
        $this->assertDatabaseHas('bill_payments', [
            'id' => $payment->id,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('bill_payment_allocations', [
            'bill_payment_id' => $payment->id,
            'bill_id' => $billA->id,
        ]);
        $this->assertDatabaseMissing('bill_payment_allocations', ['bill_id' => $billB->id]);
        $this->assertEquals(Bill::STATUS_PAID, $billA->fresh()->status);
        $this->assertTrue($billB->fresh()->canBeEdited());

        // B's share reversed, A's intact.
        $this->assertEquals(0, $this->netSum($this->account(5300)));
        $this->assertEquals(0, $this->netSum($this->account(2200)));
        $this->assertEquals(100, $this->netSum($this->account(8900)));
        $this->assertEquals(-100, $this->netSum($this->account(320)));
    }

    public function test_deleting_paid_prepaid_bill_voids_schedule_and_reverses_amortisation(): void
    {
        $bill = $this->makeBill([
            ['description' => 'Annual licence', 'quantity' => 1, 'unit_price' => 110, 'tax_rate' => 10,
             'expense_account_id' => $this->account(460)->id,
             'is_prepaid' => true,
             'service_start' => now()->subMonth()->startOfMonth()->toDateString(),
             'service_end' => now()->addMonths(11)->endOfMonth()->toDateString(),
             'amortise_to_account_id' => $this->account(7500)->id],
        ]);
        $payment = $this->payBill($bill, 110);

        // The posting spawned a prepayment schedule; amortise one month.
        $prepayment = $payment->prepayments()->first();
        $this->assertNotNull($prepayment, 'Posting must create the prepayment schedule');
        $posted = PrepaymentService::amortise($prepayment);
        $this->assertGreaterThanOrEqual(1, $posted);

        $this->actingAs($this->user)->delete(route('bills.destroy', $bill))
            ->assertRedirect(route('bills.index'));

        $this->assertDatabaseMissing('bills', ['id' => $bill->id]);

        // Schedule survives as audit, detached from the deleted items,
        // with its posted amortisation reversed (ledger nets to zero).
        $this->assertDatabaseHas('prepayments', [
            'id' => $prepayment->id,
            'status' => Prepayment::STATUS_VOID,
            'bill_item_id' => null,
        ]);
        $this->assertNotNull($prepayment->fresh()->amortisations()->first()->reversed_at);

        $this->assertEquals(0, $this->netSum($this->account(320)));
        $this->assertEquals(0, $this->netSum($this->account(460)), 'Prepaid asset legs net to zero');
        $this->assertEquals(0, $this->netSum($this->account(7500)), 'Amortisation expense nets to zero');
        $this->assertEquals(0, $this->netSum($this->account(2200)));
    }

    public function test_closed_reporting_period_blocks_deletion_and_rolls_back(): void
    {
        $bill = $this->makeBill([
            ['description' => 'Item', 'quantity' => 1, 'unit_price' => 110, 'tax_rate' => 10,
             'expense_account_id' => $this->account(8900)->id],
        ]);
        $payment = $this->payBill($bill, 110);
        $transactionsBefore = DB::table('ifrs_transactions')->count();

        ReportingPeriod::query()->update(['status' => ReportingPeriod::CLOSED]);

        $response = $this->actingAs($this->user)->delete(route('bills.destroy', $bill));

        $response->assertRedirect(route('bills.show', $bill))->assertSessionHas('error');

        // Nothing was deleted, nothing extra was posted.
        $this->assertDatabaseHas('bills', ['id' => $bill->id]);
        $this->assertDatabaseHas('bill_payments', [
            'id' => $payment->id,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('bill_payment_allocations', ['bill_id' => $bill->id]);
        $this->assertEquals($transactionsBefore, DB::table('ifrs_transactions')->count());
    }

    public function test_void_route_reverses_payment_and_destroy_is_blocked_once_posted(): void
    {
        $bill = $this->makeBill([
            ['description' => 'Item', 'quantity' => 1, 'unit_price' => 110, 'tax_rate' => 10,
             'expense_account_id' => $this->account(8900)->id],
        ]);
        $payment = $this->payBill($bill, 110);

        // Hard-deleting a posted payment would orphan its journal entry.
        $this->actingAs($this->user)->delete(route('bill-payments.destroy', $payment))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('bill_payments', ['id' => $payment->id]);

        // Voiding reverses the ledger and deallocates the bill.
        $this->actingAs($this->user)->post(route('bill-payments.void', $payment))
            ->assertSessionHas('success');

        $this->assertEquals(BillPayment::STATUS_VOID, $payment->fresh()->status);
        $this->assertEquals(Bill::STATUS_OPEN, $bill->fresh()->status);
        $this->assertEquals(0, $this->netSum($this->account(320)));
        $this->assertEquals(0, $this->netSum($this->account(8900)));
        $this->assertEquals(0, $this->netSum($this->account(2200)));

        // Voiding twice is rejected.
        $this->actingAs($this->user)->post(route('bill-payments.void', $payment))
            ->assertSessionHas('error');
    }

    public function test_service_delete_of_bill_with_mixed_shared_and_exclusive_payments(): void
    {
        // One exclusive payment on B, one shared across A and B: deleting
        // A reverses only the shared payment's A-share and leaves both
        // the exclusive payment on B and B's own paid state untouched.
        $billA = $this->makeBill([
            ['description' => 'A', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0,
             'expense_account_id' => $this->account(8900)->id],
        ]);
        $billB = $this->makeBill([
            ['description' => 'B', 'quantity' => 1, 'unit_price' => 110, 'tax_rate' => 10,
             'expense_account_id' => $this->account(5300)->id],
        ]);

        $shared = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id, 'paid_by' => $this->user->id,
            'amount' => 210, 'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $shared->allocateToBill($billA, 100);
        $shared->allocateToBill($billB, 110);
        $shared->postToIFRS();

        $exclusive = $this->payBill($billB, 110);

        $voided = BillLifecycleService::deleteBill($billA);

        $this->assertEquals(0, $voided, 'Shared payment must survive bill A\'s deletion');
        $this->assertDatabaseHas('bill_payments', [
            'id' => $shared->id, 'status' => BillPayment::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('bill_payments', [
            'id' => $exclusive->id, 'status' => BillPayment::STATUS_COMPLETED,
        ]);
        $this->assertEquals(0, $this->netSum($this->account(8900)));
    }
}
