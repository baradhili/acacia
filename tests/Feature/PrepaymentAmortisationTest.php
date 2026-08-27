<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Prepayment;
use App\Models\Supplier;
use App\Services\PrepaymentService;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Prepaid service contracts end-to-end: a paid prepaid bill line funds
 * a prepayment (asset + input GST debited), and the amortisation runner
 * expenses it monthly. Mirrors the task spec's acceptance criteria 5.1
 * and 5.4 on the cash-basis ledger (Cr Bank at payment time instead of
 * Cr Accounts Payable — this ERP posts no AP).
 */
class PrepaymentAmortisationTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;
    protected Supplier $supplier;
    protected Account $bank;
    protected Account $prepaid;
    protected Account $gstReceivable;
    protected Account $subscriptionExpense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');

        $this->entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 1,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $currency->id]);
        $this->entity->refresh();

        // CONTROL-typed Vat accounts (Vat::save enforces it; production
        // uses a CURRENT_LIABILITY via a raw seeder insert).
        $accountData = [
            ['Operating Account', Account::BANK, 320],
            ['GST Payable', Account::CONTROL, 2200],
            ['GST Receivable', Account::CONTROL, 430],
            ['Prepaid Subscriptions', Account::CURRENT_ASSET, 460],
            ['Subscriptions & Licenses', Account::OVERHEAD_EXPENSE, 7500],
            ['Insurance', Account::OVERHEAD_EXPENSE, 7300],
            ['Other Expenses', Account::OTHER_EXPENSE, 8900],
        ];
        foreach ($accountData as [$name, $type, $code]) {
            $account = Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $this->entity->id,
            ]);
            $byCode[$code] = $account;
        }
        $this->bank = $byCode[320];
        $this->prepaid = $byCode[460];
        $this->gstReceivable = $byCode[430];
        $this->subscriptionExpense = $byCode[7500];

        Vat::create([
            'name' => 'GST 10%', 'code' => 'G', 'rate' => 10,
            'account_id' => $byCode[2200]->id, 'entity_id' => $this->entity->id,
        ]);
        Vat::create([
            'name' => 'GST Input 10%', 'code' => 'I', 'rate' => 10,
            'account_id' => $this->gstReceivable->id, 'entity_id' => $this->entity->id,
        ]);

        $this->supplier = Supplier::create(['name' => 'SaaS Vendor']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function netSum(Account $account): float
    {
        return round((float) Ledger::where('post_account', $account->id)
            ->selectRaw("SUM(CASE WHEN entry_type = 'D' THEN amount ELSE -amount END) as net")
            ->value('net'), 2);
    }

    protected function payPrepaidBill(float $unitPriceInclGst, string $serviceStart, string $serviceEnd, ?Account $account = null): Prepayment
    {
        $bill = Bill::create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => $serviceStart,
            'due_date' => $serviceStart,
        ]);
        $bill->items()->create([
            'description' => 'Annual SaaS subscription',
            'quantity' => 1,
            'unit_price' => $unitPriceInclGst,
            'tax_rate' => 10,
            'gst_added' => false, // inclusive
            'expense_account_id' => ($account ?? $this->prepaid)->id,
            'is_prepaid' => true,
            'service_start' => $serviceStart,
            'service_end' => $serviceEnd,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => $bill->total,
            'payment_date' => $serviceStart,
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
        ]);
        $payment->allocateToBill($bill, (float) $bill->total);
        $this->assertNotNull($payment->postToIFRS(), $payment->lastPostingError ?? 'posting failed');

        return Prepayment::where('bill_payment_id', $payment->id)->firstOrFail();
    }

    public function test_prepaid_payment_posts_asset_and_input_gst_then_amortises_monthly(): void
    {
        // Acceptance 5.1 (cash-basis form): $1,200 + $120 GST paid up front.
        $prepayment = $this->payPrepaidBill(1320.0, '2025-07-01', '2026-06-30');

        // Ledger at payment: Dr Prepaid 1,200 / Dr GST Receivable 120 / Cr Bank 1,320.
        $this->assertSame(1200.0, $this->netSum($this->prepaid));
        $this->assertSame(120.0, $this->netSum($this->gstReceivable));
        $this->assertSame(-1320.0, $this->netSum($this->bank));

        // The funded schedule: 12 months of $100 from the first month-end.
        $this->assertSame(1200.0, (float) $prepayment->total_amount);
        $this->assertSame(100.0, (float) $prepayment->monthly_amount);
        $this->assertSame(12, $prepayment->periods);
        $this->assertSame('2025-07-31', $prepayment->next_period_date->toDateString());
        $this->assertSame($this->subscriptionExpense->id, $prepayment->expense_account_id);

        // Runner due as at July's month-end posts exactly one month.
        Artisan::call('prepayments:amortise', ['--as-of' => '2025-07-31']);
        $this->assertSame(100.0, $this->netSum($this->subscriptionExpense));  // Dr expense
        $this->assertSame(1100.0, $this->netSum($this->prepaid));            // 1,200 − 100
        $this->assertSame(1, $prepayment->amortisations()->count());

        // Rerunning the same as-of is a no-op (idempotency anchor).
        Artisan::call('prepayments:amortise', ['--as-of' => '2025-07-31']);
        $this->assertSame(1, $prepayment->amortisations()->count());
        $this->assertSame(100.0, $this->netSum($this->subscriptionExpense));

        // Backfill catch-up: as at December, five more months post.
        Artisan::call('prepayments:amortise', ['--as-of' => '2025-12-31']);
        $this->assertSame(6, $prepayment->amortisations()->count());
        $this->assertSame(600.0, $this->netSum($this->subscriptionExpense));

        // Running the schedule to its end completes it across the
        // financial-year boundary (Jan–Jun land in the next FY period).
        Artisan::call('prepayments:amortise', ['--as-of' => '2026-06-30']);
        $prepayment->refresh();
        $this->assertSame(12, $prepayment->amortisations()->count());
        $this->assertSame(Prepayment::STATUS_COMPLETED, $prepayment->status);
        $this->assertSame(1200.0, $this->netSum($this->subscriptionExpense));
        $this->assertSame(0.0, $this->netSum($this->prepaid));
        $this->assertSame(1200.0, $prepayment->amortisedAmount());
        $this->assertSame(0.0, $prepayment->remainingAmount());
    }

    public function test_final_period_absorbs_rounding_remainder(): void
    {
        // $100 over 3 months: 33.33 + 33.33 + 33.34.
        $prepayment = $this->payPrepaidBill(110.0, '2025-07-01', '2025-09-30');
        $this->assertSame(100.0, (float) $prepayment->total_amount);
        $this->assertSame(33.33, (float) $prepayment->monthly_amount);

        Artisan::call('prepayments:amortise', ['--as-of' => '2025-09-30']);

        $amounts = $prepayment->amortisations()->pluck('amount')->all();
        $this->assertSame([33.33, 33.33, 33.34], array_map(fn ($a) => (float) $a, $amounts));
        $this->assertSame(100.0, $this->netSum($this->subscriptionExpense));
    }

    public function test_non_prepaid_line_posts_straight_to_expense(): void
    {
        // Acceptance 5.4 (monthly billing): no prepayment is created.
        $bill = Bill::create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2025-07-15',
            'due_date' => '2025-08-14',
        ]);
        $bill->items()->create([
            'description' => 'Monthly SaaS (billed in arrears)',
            'quantity' => 1,
            'unit_price' => 110,
            'tax_rate' => 10,
            'gst_added' => false,
            'expense_account_id' => $this->subscriptionExpense->id,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => $bill->total,
            'payment_date' => '2025-07-15',
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
        ]);
        $payment->allocateToBill($bill, (float) $bill->total);
        $this->assertNotNull($payment->postToIFRS());

        $this->assertSame(0, Prepayment::count());
        $this->assertSame(100.0, $this->netSum($this->subscriptionExpense));
        $this->assertSame(10.0, $this->netSum($this->gstReceivable));
    }

    public function test_dry_run_posts_nothing(): void
    {
        $prepayment = $this->payPrepaidBill(1320.0, '2025-07-01', '2026-06-30');

        Artisan::call('prepayments:amortise', ['--as-of' => '2025-10-31', '--dry-run' => true]);

        $this->assertSame(0, $prepayment->amortisations()->count());
        $this->assertSame(0.0, $this->netSum($this->subscriptionExpense));
        $this->assertSame(1200.0, $this->netSum($this->prepaid));
    }

    public function test_closed_reporting_period_stops_that_prepayment(): void
    {
        $prepayment = $this->payPrepaidBill(1320.0, '2025-07-01', '2026-06-30');

        ReportingPeriod::where('entity_id', $this->entity->id)
            ->where('calendar_year', 2025)
            ->update(['status' => ReportingPeriod::CLOSED]);

        $exit = Artisan::call('prepayments:amortise', ['--as-of' => '2025-07-31']);

        $this->assertSame(1, $exit); // FAILED, not silently skipped
        $this->assertSame(0, $prepayment->amortisations()->count());
        $this->assertSame(0.0, $this->netSum($this->subscriptionExpense));
    }

    public function test_reversal_nets_a_month_back_to_zero(): void
    {
        $prepayment = $this->payPrepaidBill(1320.0, '2025-07-01', '2026-06-30');

        Artisan::call('prepayments:amortise', ['--as-of' => '2025-07-31']);
        $this->assertSame(100.0, $this->netSum($this->subscriptionExpense));

        $entry = $prepayment->amortisations()->first();
        $this->assertNotNull(PrepaymentService::reverseAmortisation($entry));

        // The mirrored entry restores both legs for that month.
        $this->assertSame(0.0, $this->netSum($this->subscriptionExpense));
        $this->assertSame(1200.0, $this->netSum($this->prepaid));
        $this->assertTrue($entry->refresh()->isReversed());
    }
}
