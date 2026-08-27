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
            ['Domain Names', Account::NON_CURRENT_ASSET, 170],
            ['Subscriptions & Licenses', Account::OVERHEAD_EXPENSE, 7500],
            ['Domain Renewal Expense', Account::OVERHEAD_EXPENSE, 7510],
            ['Amortisation Expense', Account::OVERHEAD_EXPENSE, 7910],
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

    public function test_prepayment_screens_and_schedule_report_render(): void
    {
        $user = \App\Models\User::factory()->create();
        $user->entity_id = $this->entity->id;
        $user->save();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $user->assignRole('admin');

        $prepayment = $this->payPrepaidBill(1320.0, '2025-07-01', '2026-06-30');
        Artisan::call('prepayments:amortise', ['--as-of' => '2025-08-31']);

        $this->actingAs($user)->get(route('prepayments.index'))
            ->assertStatus(200)
            ->assertSee('Annual SaaS subscription')
            ->assertSee('$1,200.00');

        $this->actingAs($user)->get(route('prepayments.show', $prepayment))
            ->assertStatus(200)
            ->assertSee('Posted')
            ->assertSee('Planned')
            ->assertSee('Run amortisation to date');

        $this->actingAs($user)->get(route('reports.prepayment-schedule'))
            ->assertStatus(200)
            ->assertSee('Amortisation Schedule')
            ->assertSee('JE #');

        $this->actingAs($user)->get(route('reports.export.prepayment-schedule.pdf'))
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * Pay a single-line (non-prepaid) bill coded to an arbitrary account.
     */
    protected function payBillLine(float $unitPriceInclGst, Account $account): void
    {
        $bill = Bill::create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2025-07-01',
            'due_date' => '2025-07-01',
        ]);
        $bill->items()->create([
            'description' => 'Bill line to ' . $account->code,
            'quantity' => 1,
            'unit_price' => $unitPriceInclGst,
            'tax_rate' => 10,
            'gst_added' => false,
            'expense_account_id' => $account->id,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => $bill->total,
            'payment_date' => '2025-07-01',
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
        ]);
        $payment->allocateToBill($bill, (float) $bill->total);
        $this->assertNotNull($payment->postToIFRS(), $payment->lastPostingError ?? 'posting failed');
    }

    public function test_domain_purchase_capitalises_and_renewal_leaves_asset_unchanged(): void
    {
        $domain = Account::where('code', 170)->first();
        $renewal = Account::where('code', 7510)->first();

        // Acceptance 5.2 (cash-basis form): $5,000 + $500 GST purchase.
        $this->payBillLine(5500.0, $domain);
        $this->assertSame(5000.0, $this->netSum($domain));
        $this->assertSame(500.0, $this->netSum($this->gstReceivable));

        // Acceptance 5.3: $50 + $5 GST renewal is expensed immediately
        // and the intangible carrying amount is untouched.
        $this->payBillLine(55.0, $renewal);
        $this->assertSame(50.0, $this->netSum($renewal));
        $this->assertSame(505.0, $this->netSum($this->gstReceivable));
        $this->assertSame(5000.0, $this->netSum($domain));
    }

    public function test_finite_life_domain_amortises_from_the_registry(): void
    {
        $user = \App\Models\User::factory()->create();
        $user->entity_id = $this->entity->id;
        $user->save();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $user->assignRole('admin');

        $domain = \App\Models\Domain::create([
            'entity_id' => $this->entity->id,
            'name' => 'example.com.au',
            'cost' => 2400,
            'indefinite_life' => false,
            'useful_life_months' => 24,
            'purchased_at' => '2025-07-01',
        ]);

        $response = $this->actingAs($user)
            ->post(route('domains.amortisation', $domain));
        $response->assertRedirect(route('prepayments.index'));

        $prepayment = $domain->prepayments()->firstOrFail();
        $this->assertSame(2400.0, (float) $prepayment->total_amount);
        $this->assertSame(24, $prepayment->periods);
        $this->assertSame(100.0, (float) $prepayment->monthly_amount);
        $this->assertSame(170, (int) $prepayment->assetAccount->code);
        $this->assertSame(7910, (int) $prepayment->expenseAccount->code);

        Artisan::call('prepayments:amortise', ['--as-of' => '2025-07-31']);
        $this->assertSame(100.0, $this->netSum(Account::where('code', 7910)->first())); // Dr amortisation
        $this->assertSame(-100.0, $this->netSum(Account::where('code', 170)->first())); // Cr intangible
    }

    public function test_indefinite_life_domain_rejects_amortisation(): void
    {
        $user = \App\Models\User::factory()->create();
        $user->entity_id = $this->entity->id;
        $user->save();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $user->assignRole('admin');

        $domain = \App\Models\Domain::create([
            'entity_id' => $this->entity->id,
            'name' => 'forever.example.com',
            'cost' => 5000,
            'indefinite_life' => true,
            'purchased_at' => '2025-07-01',
        ]);

        $this->actingAs($user)
            ->post(route('domains.amortisation', $domain))
            ->assertRedirect(route('domains.show', $domain))
            ->assertSessionHas('error');

        $this->assertSame(0, \App\Models\Prepayment::count());
    }
}
