<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IfrsReportsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations
        $this->artisan('migrate');

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'accountant']);

        // Create IFRS entity first
        $this->entity = Entity::create([
            'name' => 'Test Entity',
            'currency_id' => 1,
            // July FY start — matches the test data below (Aug = Q1,
            // May = Q4, fy=2026 meaning Jul 2025 – Jun 2026) and the
            // production seeder; BAS/company-tax FY boundaries are
            // entity-derived.
            'year_start' => 7,
            'multi_currency' => false,
        ]);

        // The entity's currency_id must reference a real currency row for
        // account creation (FK) to work.
        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $currency->id]);
        $this->entity->refresh();

        // Create IFRS reporting period
        ReportingPeriod::create([
            'entity_id' => $this->entity->id,
            'year' => Carbon::now()->year,
            'calendar_year' => Carbon::now()->year,
            'period' => Carbon::now()->month,
            'period_count' => 1,
            'start_date' => Carbon::now()->startOfMonth(),
            'end_date' => Carbon::now()->endOfMonth(),
            'status' => ReportingPeriod::OPEN,
        ]);

        // Create user with entity relationship
        $this->user = User::factory()->create();
        $this->user->entity_id = $this->entity->id;
        $this->user->save();
        $this->user->assignRole('admin');
    }

    // ============================================================
    // Entity assignment
    // ============================================================
    public function test_entity_less_user_cannot_read_reports(): void
    {
        // No entity_id — the self-registration edge. Reports must refuse
        // (404) rather than lend the posting fallback's first entity.
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->get(route('reports.trial-balance'))
            ->assertStatus(404);

        $this->actingAs($user)
            ->get(route('reports.account-statement'))
            ->assertStatus(404);
    }

    // ============================================================
    // Account Statement Export Tests
    // ============================================================
    public function test_account_statement_page_loads(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.account-statement'));

        $response->assertStatus(200);
        $response->assertSee('Account Statement');
    }

    public function test_account_statement_export_pdf_requires_account(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.export.account-statement.pdf'));

        $response->assertStatus(302);
        $response->assertSessionHas('error');
    }

    // ============================================================
    // Account Schedule Export Tests
    // ============================================================
    public function test_account_schedule_page_loads(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.account-schedule'));

        $response->assertStatus(200);
        $response->assertSee('Account Schedule');
    }

    public function test_bill_views_offer_capital_purchase_categories(): void
    {
        Account::create([
            'name' => 'Tools & Equipment',
            'account_type' => Account::NON_CURRENT_ASSET,
            'code' => 150,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);
        Account::create([
            'name' => 'Software',
            'account_type' => Account::NON_CURRENT_ASSET,
            'code' => 160,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);
        Account::create([
            'name' => 'Office Supplies',
            'account_type' => Account::OPERATING_EXPENSE,
            'code' => 5600,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('bills.create'))
            ->assertStatus(200)
            ->assertSee('Capital purchases')
            ->assertSee('Tools & Equipment')
            ->assertSee('Software')
            ->assertSee('Expenses');

        $supplier = Supplier::create(['name' => 'Test Supplier']);
        $bill = Bill::createWithUniqueNumber(['supplier_id' => $supplier->id]);
        $bill->items()->create([
            'description' => 'Drill',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
            'gst_added' => true,
        ]);
        $bill->recalculateTotals();

        $this->actingAs($this->user)
            ->get(route('bills.edit', $bill))
            ->assertStatus(200)
            ->assertSee('Capital purchases')
            ->assertSee('Tools & Equipment');
    }

    // ============================================================
    // Bill IFRS Journal Entry Tests (per-line GST posting is covered
    // in Unit/BillPaymentModelTest)
    // ============================================================
    public function test_bill_can_be_marked_as_paid(): void
    {
        $supplier = Supplier::create(['name' => 'Test Supplier']);

        $bill = Bill::createWithUniqueNumber(['supplier_id' => $supplier->id]);
        $bill->items()->create([
            'description' => 'Office supplies',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'paid_by' => $this->user->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 110);

        $bill->refresh();

        $this->assertEquals(Bill::STATUS_PAID, $bill->status);
        $this->assertNotNull($bill->paid_at);
    }

    // ============================================================
    // Account Schedule scoping — the ledger is the truth, not line
    // items (a journal's main-account leg is never a line item)
    // ============================================================

    /**
     * Post a payroll-shaped journal the PayrollService way: the main
     * account takes one side, the legs the other.
     */
    protected function postPayrollJournal(Account $main, bool $credited, array $legs, string $reference): void
    {
        $journal = new JournalEntry([
            'transaction_date' => Carbon::now()->toDateString(),
            'account_id' => $main->id,
            'credited' => $credited,
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'narration' => 'Payroll — test run',
            'reference' => $reference,
        ]);

        foreach ($legs as [$account, $amount]) {
            $line = LineItem::create([
                'account_id' => $account->id,
                'amount' => $amount,
                'quantity' => 1,
                'entity_id' => $this->entity->id,
            ]);
            $journal->addLineItem($line);
        }

        $journal->post();
    }

    public function test_account_schedule_is_scoped_to_the_accounts_own_movement(): void
    {
        [$wages, $payg, $wagesPayable] = [
            Account::create(['name' => 'Salaries & Wages', 'account_type' => Account::OPERATING_EXPENSE, 'code' => 5100, 'currency_id' => $this->entity->currency_id, 'entity_id' => $this->entity->id]),
            Account::create(['name' => 'PAYG Withholding Payable', 'account_type' => Account::CURRENT_LIABILITY, 'code' => 2210, 'currency_id' => $this->entity->currency_id, 'entity_id' => $this->entity->id]),
            Account::create(['name' => 'Wages Payable', 'account_type' => Account::CURRENT_LIABILITY, 'code' => 2235, 'currency_id' => $this->entity->currency_id, 'entity_id' => $this->entity->id]),
        ];

        // Accrual: Dr wages 1,000 / Cr PAYG 200 / Cr wages payable 800 —
        // the shape that used to show the whole item side (1,000) on the
        // wages-payable schedule.
        $this->postPayrollJournal($wages, false, [[$payg, 200], [$wagesPayable, 800]], 'PAYROLL-9-ACC');

        $response = $this->actingAs($this->user)
            ->get(route('reports.account-schedule', [
                'account_id' => $wagesPayable->id,
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->endOfMonth()->toDateString(),
            ]));

        $schedule = $response->viewData('scheduleData');

        // The account's own movement only — the PAYG leg (200) and the
        // expense main leg (1,000) never leak into the totals.
        $this->assertEquals(0.0, (float) $schedule['total_debit']);
        $this->assertEquals(800.0, (float) $schedule['total_credit']);

        $card = collect($schedule['lines'])->firstWhere('reference', 'PAYROLL-9-ACC');
        $this->assertNotNull($card);
        $this->assertEquals(0.0, (float) $card['debit']);
        $this->assertEquals(800.0, (float) $card['credit']);

        // The card shows the complete journal. The package posts each
        // line item as its own main/item pair (Dr wages / Cr the item),
        // so the main account — invisible to the old line-item query —
        // now appears beside every leg it balances.
        $legs = collect($card['line_items']);
        $this->assertTrue($legs->contains(fn ($leg) => $leg['account'] === '5100 - Salaries & Wages' && (float) $leg['debit'] === 800.0));
        $this->assertTrue($legs->contains(fn ($leg) => $leg['account'] === '2235 - Wages Payable' && (float) $leg['credit'] === 800.0));
        $this->assertTrue($legs->contains(fn ($leg) => $leg['account'] === '2210 - PAYG Withholding Payable' && (float) $leg['credit'] === 200.0));
    }

    public function test_account_schedule_lists_transactions_where_the_account_is_the_main_leg(): void
    {
        [$wagesPayable, $bank] = [
            Account::create(['name' => 'Wages Payable', 'account_type' => Account::CURRENT_LIABILITY, 'code' => 2235, 'currency_id' => $this->entity->currency_id, 'entity_id' => $this->entity->id]),
            Account::create(['name' => 'Operating Account', 'account_type' => Account::BANK, 'code' => 320, 'currency_id' => $this->entity->currency_id, 'entity_id' => $this->entity->id]),
        ];

        // Net pay: Dr wages payable (the MAIN account) / Cr bank — the
        // old line-item query missed this transaction entirely, because
        // the main-account leg is never a line item.
        $this->postPayrollJournal($wagesPayable, false, [[$bank, 800]], 'PAYROLL-9-PAY');

        $response = $this->actingAs($this->user)
            ->get(route('reports.account-schedule', [
                'account_id' => $wagesPayable->id,
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->endOfMonth()->toDateString(),
            ]));

        $schedule = $response->viewData('scheduleData');

        $card = collect($schedule['lines'])->firstWhere('reference', 'PAYROLL-9-PAY');
        $this->assertNotNull($card);
        $this->assertEquals(800.0, (float) $card['debit']);
        $this->assertEquals(0.0, (float) $card['credit']);
    }
}
