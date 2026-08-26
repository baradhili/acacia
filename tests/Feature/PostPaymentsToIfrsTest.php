<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\User;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostPaymentsToIfrsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Client $client;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIfrs();

        $this->user = User::factory()->create();
        $this->client = Client::factory()->create();
        $this->supplier = Supplier::create(['name' => 'Test Supplier']);
    }

    /**
     * Seed the minimum IFRS prerequisites for payment posting: currency,
     * entity + reporting period, bank (320), revenue (4100), GST Payable
     * (2200), the GST 10% Vat, and the expense accounts bills post to.
     * Mirrors BillPaymentModelTest::seedIfrs().
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
            ['Consulting Revenue', Account::OPERATING_REVENUE, 4100],
            // The IFRS package requires Vat accounts to be CONTROL type
            // (Vat::save enforces it); the production seeder bypasses this
            // with a raw DB insert pointing at a CURRENT_LIABILITY account.
            ['GST Payable', Account::CONTROL, 2200],
            ['Travel & Accommodation', Account::OPERATING_EXPENSE, 5300],
            ['Bank Charges', Account::OVERHEAD_EXPENSE, 7800],
            ['Other Expenses', Account::OTHER_EXPENSE, 8900],
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

        $gstPayable = Account::where('code', 2200)->first();
        Vat::create([
            'name' => 'GST 10%',
            'code' => 'G',
            'rate' => 10,
            'account_id' => $gstPayable->id,
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

    protected function createOpenBill(float $unitPrice = 110, float $taxRate = 0, ?int $expenseAccountId = null): Bill
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'Bill item',
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'expense_account_id' => $expenseAccountId,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        return $bill;
    }

    public function test_client_payment_via_controller_posts_to_ifrs(): void
    {
        $response = $this->actingAs($this->user)->post('/payments', [
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'no',
        ]);

        $response->assertSessionHas('success');

        $payment = Payment::first();
        $this->assertNotNull($payment->ifrs_receipt_id, 'Controller flow must post the receipt to IFRS.');
        $this->assertDatabaseHas('ifrs_transactions', ['id' => $payment->ifrs_receipt_id]);

        $bank = Account::where('code', 320)->first();
        $revenue = Account::where('code', 4100)->first();
        $gst = Account::where('code', 2200)->first();

        // Dr Bank the full tax-inclusive amount.
        $this->assertEquals(110, $this->debitSum($bank));
        // Cr Revenue the net amount: 110 basic credit less the 10 debit
        // contra the VAT split posts against the same account.
        $this->assertEquals(100, $this->netSum($revenue) * -1);
        // Cr GST Payable the GST component.
        $this->assertEquals(10, $this->creditSum($gst) - $this->debitSum($gst));
    }

    public function test_payment_in_fy_without_period_row_auto_creates_period(): void
    {
        // seedIfrs() only creates the current year's period; a payment dated
        // five years back has no period row and previously failed forever.
        $oldYear = (int) now()->subYears(5)->format('Y');

        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => "{$oldYear}-06-15",
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertNotNull($payment->postToIFRS());
        $this->assertDatabaseHas('ifrs_reporting_periods', [
            'entity_id' => Entity::first()->id,
            'calendar_year' => $oldYear,
            'status' => ReportingPeriod::OPEN,
        ]);
    }

    public function test_payment_dated_on_fiscal_year_start_still_posts(): void
    {
        // Jan 1 is the period start instant for this year_start=1 entity;
        // the package reserves that exact moment for Balance objects, so a
        // date-only payment date must be nudged past midnight.
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->startOfYear()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertNotNull($payment->postToIFRS());

        $bank = Account::where('code', 320)->first();
        $this->assertEquals(110, $this->debitSum($bank));
    }

    public function test_credit_note_refund_payment_posts_flipped_legs(): void
    {
        // Credit-note refunds are negative payments; they must post the
        // absolute amount with every leg flipped (Cr Bank / Dr Revenue /
        // Dr GST) since IFRS line items reject negative amounts.
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => -110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'other',
        ]);

        $this->assertNotNull($payment->postToIFRS());

        $bank = Account::where('code', 320)->first();
        $revenue = Account::where('code', 4100)->first();
        $gst = Account::where('code', 2200)->first();

        $this->assertEquals(110, $this->creditSum($bank));
        $this->assertEquals(100, $this->netSum($revenue));
        $this->assertEquals(10, $this->netSum($gst));
    }

    public function test_post_payments_command_posts_only_unposted_and_is_idempotent(): void
    {
        $expenseAccount = Account::where('code', 8900)->first();

        $posted = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $posted->postToIFRS();

        $void = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $void->void();

        $unposted = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 55,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $bill = $this->createOpenBill(100, 0, $expenseAccount->id);
        $unpostedBillPayment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $unpostedBillPayment->allocateToBill($bill, 100);

        $this->artisan('ifrs:post-payments')->assertExitCode(0);

        $this->assertNotNull($unposted->fresh()->ifrs_receipt_id);
        $this->assertNotNull($unpostedBillPayment->fresh()->ifrs_payment_id);
        $this->assertNull($void->fresh()->ifrs_receipt_id, 'Void payments must not be posted.');

        // 3 transactions total: pre-posted receipt, command-posted receipt,
        // command-posted supplier payment. Void contributed none.
        $this->assertEquals(3, \DB::table('ifrs_transactions')->count());

        // Rerun is a no-op.
        $this->artisan('ifrs:post-payments')->assertExitCode(0);
        $this->assertEquals(3, \DB::table('ifrs_transactions')->count());
    }

    public function test_post_payments_command_dry_run_posts_nothing(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->artisan('ifrs:post-payments', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($payment->fresh()->ifrs_receipt_id);
        $this->assertEquals(0, \DB::table('ifrs_transactions')->count());
    }

    public function test_post_payments_command_reports_failure_reason(): void
    {
        Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        Account::query()->delete(); // prerequisites gone → posting must fail

        $this->artisan('ifrs:post-payments')
            ->expectsOutputToContain('FAILED')
            ->assertExitCode(1);
    }

    public function test_voiding_posted_payment_posts_reversing_entry(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->postToIFRS();

        $this->assertTrue($payment->void());

        $this->assertDatabaseHas('ifrs_transactions', [
            'narration' => "Reversal of payment: {$payment->payment_number} (voided)",
        ]);

        $bank = Account::where('code', 320)->first();
        $revenue = Account::where('code', 4100)->first();
        $gst = Account::where('code', 2200)->first();

        // Original + reversal net to zero on every account.
        $this->assertEquals(0, $this->netSum($bank));
        $this->assertEquals(0, $this->netSum($revenue));
        $this->assertEquals(0, $this->netSum($gst));
    }

    public function test_voiding_posted_bill_payment_posts_reversing_entry(): void
    {
        $expenseAccount = Account::where('code', 8900)->first();
        $bill = $this->createOpenBill(110, 10, $expenseAccount->id); // GST-inclusive

        $billPayment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $billPayment->allocateToBill($bill, 110);
        $this->assertNotNull($billPayment->postToIFRS());

        $this->assertTrue($billPayment->void());

        $this->assertDatabaseHas('ifrs_transactions', [
            'narration' => "Reversal of supplier payment: {$billPayment->payment_number} (voided)",
        ]);

        $bank = Account::where('code', 320)->first();
        $gst = Account::where('code', 2200)->first();

        $this->assertEquals(0, $this->netSum($bank));
        $this->assertEquals(0, $this->netSum($expenseAccount));
        $this->assertEquals(0, $this->netSum($gst));
    }

    public function test_voiding_unposted_payment_writes_no_ledger(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertTrue($payment->void());

        $this->assertEquals(0, \DB::table('ifrs_transactions')->count());
        $this->assertEquals(0, \DB::table('ifrs_ledgers')->count());
    }
}
