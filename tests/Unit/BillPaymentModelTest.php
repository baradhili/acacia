<?php

namespace Tests\Unit;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Supplier;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillPaymentModelTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = Supplier::create(['name' => 'Test Supplier']);
    }

    /**
     * Seed the minimum IFRS prerequisites for supplier-payment posting:
     * currency, entity + reporting period, bank (320), GST Payable (2200),
     * the GST 10% Vat, and the expense accounts the bills post to.
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

    public function test_generates_spay_number(): void
    {
        $payment = BillPayment::create([
            'supplier_id' => $this->supplier->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertMatchesRegularExpression('/^SPAY-' . date('Y') . '-\d{4}$/', $payment->payment_number);
    }

    public function test_post_to_ifrs_creates_per_line_gst_entries(): void
    {
        $this->seedIfrs();

        $travelAccount = Account::where('code', 5300)->first();
        $bankChargesAccount = Account::where('code', 7800)->first();

        // Mixed-GST bill: $110 taxable (travel) + $50 GST-free (bank fee).
        // GST-inclusive line of 110 = 100 net + 10 GST.
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'Taxable travel',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
            'expense_account_id' => $travelAccount->id,
        ]);
        $bill->items()->create([
            'description' => 'GST-free bank fee',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 0,
            'expense_account_id' => $bankChargesAccount->id,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();
        $this->assertEquals(160, (float) $bill->total);

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 160,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 160);

        $ifrsId = $payment->postToIFRS();

        $this->assertNotNull($ifrsId);
        $payment->refresh();
        $this->assertEquals($ifrsId, $payment->ifrs_payment_id);

        $bank = Account::where('code', 320)->first();
        $gst = Account::where('code', 2200)->first();

        // Cr Bank 160 (gross)
        $bankCredit = Ledger::where('post_account', $bank->id)
            ->where('entry_type', Balance::CREDIT)->sum('amount');
        $this->assertEquals(160, (float) $bankCredit);

        // Dr Travel net 100: the basic row debits the full tax-inclusive 110
        // and the VAT split adds a 10 credit contra against the same account,
        // so the net debit is 100 (net-of-GST expense).
        $travelDebit = Ledger::where('post_account', $travelAccount->id)
                ->where('entry_type', Balance::DEBIT)->sum('amount')
            - Ledger::where('post_account', $travelAccount->id)
                ->where('entry_type', Balance::CREDIT)->sum('amount');
        $this->assertEquals(100, (float) $travelDebit);

        // Dr Bank Charges 50 in full (GST-free)
        $feeDebit = Ledger::where('post_account', $bankChargesAccount->id)
            ->where('entry_type', Balance::DEBIT)->sum('amount');
        $this->assertEquals(50, (float) $feeDebit);

        // Dr GST Payable 10 (input tax, contra row against the bank account)
        $gstDebit = Ledger::where('post_account', $gst->id)
            ->where('entry_type', Balance::DEBIT)->sum('amount');
        $this->assertEquals(10, (float) $gstDebit);
    }

    public function test_post_to_ifrs_is_idempotent(): void
    {
        $this->seedIfrs();

        $expenseAccount = Account::where('code', 8900)->first();
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'expense_account_id' => $expenseAccount->id,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 100);

        $first = $payment->postToIFRS();
        $second = $payment->postToIFRS();

        $this->assertEquals($first, $second);
        $this->assertEquals(1, \DB::table('ifrs_transactions')->count());
    }

    public function test_post_to_ifrs_returns_null_without_accounts(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 110);

        // No IFRS accounts seeded — posting logs and returns null without throwing.
        $this->assertNull($payment->postToIFRS());
        $this->assertFalse($payment->fresh()->is_posted_to_i_f_r_s);
    }

    public function test_void_deletes_allocations_and_recomputes(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 110);
        $this->assertEquals(Bill::STATUS_PAID, $bill->fresh()->status);

        $this->assertTrue($payment->void());
        $this->assertFalse($payment->void()); // already void

        $this->assertEquals(Bill::STATUS_OPEN, $bill->fresh()->status);
    }
}
