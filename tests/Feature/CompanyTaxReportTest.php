<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\Vat;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTaxReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Entity $entity;
    protected Account $bank;
    protected Vat $gstVat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');

        Role::firstOrCreate(['name' => 'admin']);

        $this->entity = Entity::create([
            'name' => 'Test Entity',
            'currency_id' => 1,
            'year_start' => 7,
            'multi_currency' => false,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $currency->id]);
        $this->entity->refresh();

        $this->user = User::factory()->create();
        $this->user->entity_id = $this->entity->id;
        $this->user->save();
        $this->user->assignRole('admin');

        // Mini chart of accounts covering every mapping path exercised
        // by the tests below.
        $account = function (string $name, string $type, int $code) {
            return Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $this->entity->currency_id,
                'entity_id' => $this->entity->id,
            ]);
        };
        $this->bank = $account('Operating Account', Account::BANK, 320);
        $account('Consulting Revenue', Account::OPERATING_REVENUE, 4100);
        $account('Interest Income', Account::NON_OPERATING_REVENUE, 4510);
        $account('Insurance', Account::OVERHEAD_EXPENSE, 7300);
        $account('Meals & Entertainment', Account::OPERATING_EXPENSE, 5500);
        $account('Contract Labour', Account::OPERATING_EXPENSE, 5110);
        $account('Depreciation Expense', Account::OVERHEAD_EXPENSE, 7900);
        $account('Accumulated Depreciation', Account::CONTRA_ASSET, 190);
        $account('Tools & Equipment', Account::NON_CURRENT_ASSET, 150);

        $gstPayable = $account('GST Payable', Account::CURRENT_LIABILITY, 2200);
        // Insert via the query builder like IFRSSeeder does: the package's
        // Vat::save() insists on a CONTROL-type account, but the app's
        // chart of accounts keeps GST in a current liability (2200).
        DB::table('ifrs_vats')->insert([
            'name' => 'GST 10%',
            'code' => 'G',
            'rate' => 10,
            'account_id' => $gstPayable->id,
            'entity_id' => $this->entity->id,
        ]);
        $this->gstVat = Vat::where('entity_id', $this->entity->id)->where('code', 'G')->first();
    }

    /**
     * Post a bank receipt the way Payment::postToIFRS() does:
     * Dr Bank / Cr revenue line (vat_inclusive splits the GST out).
     */
    private function postReceipt(string $date, float $gross, int $revenueCode, bool $withGst = true): void
    {
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $journalEntry = new JournalEntry([
            'transaction_date' => Carbon::parse($date),
            'account_id' => $this->bank->id,
            'credited' => false,
            'entity_id' => $this->entity->id,
            'narration' => 'Payment received: TEST',
            'reference' => 'TEST-RCPT',
        ]);

        $line = LineItem::create([
            'account_id' => Account::where('entity_id', $this->entity->id)->where('code', $revenueCode)->value('id'),
            'amount' => $gross,
            'quantity' => 1,
            'vat_inclusive' => $withGst,
            'entity_id' => $this->entity->id,
        ]);
        if ($withGst) {
            $line->addVat($this->gstVat);
            $line->save();
        }
        $journalEntry->addLineItem($line);
        $journalEntry->post();
    }

    /**
     * Post a bank payment the way BillPayment::postToIFRS() does:
     * Cr Bank / Dr expense line (vat_inclusive splits the GST out).
     */
    private function postPayment(string $date, float $gross, int $expenseCode, bool $withGst = true): void
    {
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $journalEntry = new JournalEntry([
            'transaction_date' => Carbon::parse($date),
            'account_id' => $this->bank->id,
            'credited' => true,
            'entity_id' => $this->entity->id,
            'narration' => 'Supplier payment: TEST',
            'reference' => 'TEST-PAY',
        ]);

        $line = LineItem::create([
            'account_id' => Account::where('entity_id', $this->entity->id)->where('code', $expenseCode)->value('id'),
            'amount' => $gross,
            'quantity' => 1,
            'vat_inclusive' => $withGst,
            'entity_id' => $this->entity->id,
        ]);
        if ($withGst) {
            $line->addVat($this->gstVat);
            $line->save();
        }
        $journalEntry->addLineItem($line);
        $journalEntry->post();
    }

    /**
     * Post a non-cash depreciation journal (main account is NOT a bank —
     * must be excluded from every Item 6 label).
     */
    private function postDepreciation(string $date, float $amount): void
    {
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $journalEntry = new JournalEntry([
            'transaction_date' => Carbon::parse($date),
            'account_id' => Account::where('entity_id', $this->entity->id)->where('code', 7900)->value('id'),
            'credited' => false,
            'entity_id' => $this->entity->id,
            'narration' => 'Depreciation charge',
            'reference' => 'DEPN',
        ]);
        $line = LineItem::create([
            'account_id' => Account::where('entity_id', $this->entity->id)->where('code', 190)->value('id'),
            'amount' => $amount,
            'quantity' => 1,
            'entity_id' => $this->entity->id,
        ]);
        $journalEntry->addLineItem($line);
        $journalEntry->post();
    }

    public function test_company_tax_page_loads(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.company-tax'));

        $response->assertStatus(200);
        $response->assertSee('Company Tax Return');
        $response->assertSee('Item 6 — Income');
        $response->assertSee('Item 6 — Expenses');
        $response->assertSee('Item 7 — Reconciliation');
        $response->assertSee('Item 8 — Financial and other information');
        $response->assertSee('V13');
    }

    public function test_company_tax_requires_authentication(): void
    {
        $this->get(route('reports.company-tax'))->assertRedirect('/login');
    }

    public function test_company_tax_allocates_amounts_to_form_labels(): void
    {
        // Income: $110 receipt incl GST → 6-C $100 net.
        $this->postReceipt('2025-09-15', 110, 4100);
        // Interest: $11 GST-free → 6-F $11.
        $this->postReceipt('2026-03-10', 11, 4510, withGst: false);

        // Expenses: insurance $110 incl → 6-S $100; meals $55 incl →
        // 6-S $50 (also added back at 7-W); contractors $220 incl → 6-C $200.
        $this->postPayment('2025-12-01', 110, 7300);
        $this->postPayment('2026-02-14', 55, 5500);
        $this->postPayment('2026-04-02', 220, 5110);

        // Capital purchase: $1,100 incl GST → Item 10 reference $1,000,
        // never an Item 6 expense.
        $this->postPayment('2026-05-30', 1100, 150);

        // Non-cash depreciation — must be excluded everywhere.
        $this->postDepreciation('2026-06-15', 500);

        $response = $this->actingAs($this->user)
            ->get(route('reports.company-tax', ['fy' => 2026]));

        $response->assertStatus(200);

        // Item 6 income labels.
        $response->assertSee('Other sales of goods and services');
        $response->assertSee('Gross interest');
        $response->assertSee('$100'); // 6-C net of GST
        $response->assertSee('$11');  // 6-F

        // Totals: 6-S income $111, 6-Q expenses $350 (100+50+200),
        // 6-T loss -$239, 7-W add-back $50, 7-T loss -$189.
        $response->assertSee('$111');
        $response->assertSee('$350');
        $response->assertSee('-$239');
        $response->assertSee('Add back: Non-deductible expenses');
        $response->assertSee('$50');
        $response->assertSee('-$189');

        // GST cross-checks: collected $10, paid $135 (10+5+20+100).
        $response->assertSee('$10');
        $response->assertSee('$135');

        // Capital purchases appear at Item 10 only.
        $response->assertSee('SBE simplified depreciation');
        $response->assertSee('$1,000');

        // Depreciation ($500) never reaches the labels.
        $response->assertDontSee('$500');

        // Bank-flow validations tie exactly: inflows 121 = 111+10,
        // outflows 1,485 = 350+135+1,000.
        $response->assertSee('Bank inflows 121 vs expected 121');
        $response->assertSee('Bank outflows 1485 vs expected 1485');
    }

    public function test_company_tax_warns_on_missing_abn_tfn(): void
    {
        config(['australian.abn' => '', 'australian.tfn' => '']);

        $response = $this->actingAs($this->user)
            ->get(route('reports.company-tax'));

        $response->assertStatus(200);
        $response->assertSee('COMPANY_ABN');
        $response->assertSee('not configured');

        config(['australian.abn' => '51824753556', 'australian.tfn' => '123456789']);

        $response = $this->actingAs($this->user)
            ->get(route('reports.company-tax'));

        $response->assertSee('51824753556');
        $response->assertSee('123456789');
    }

    public function test_company_tax_falls_back_and_warns_for_unmapped_accounts(): void
    {
        $unmapped = Account::create([
            'name' => 'Late Fee Income',
            'account_type' => Account::OPERATING_REVENUE,
            'code' => 4999,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);

        $this->postReceipt('2025-10-20', 21, 4999, withGst: false);

        $response = $this->actingAs($this->user)
            ->get(route('reports.company-tax', ['fy' => 2026]));

        $response->assertStatus(200);
        $response->assertSee('not mapped in config/ato_tax_report.php');
        $response->assertSee('reported at Item 6 label C');
        // The amount still lands in the 6-C fallback bucket.
        $response->assertSee('$21');
    }

    public function test_company_tax_exports(): void
    {
        $this->postReceipt('2025-09-15', 110, 4100);

        $pdf = $this->actingAs($this->user)
            ->get(route('reports.export.company-tax.pdf', ['fy' => 2026]));
        $pdf->assertStatus(200);
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        $xlsx = $this->actingAs($this->user)
            ->get(route('reports.export.company-tax.excel', ['fy' => 2026]));
        $xlsx->assertStatus(200);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $xlsx->headers->get('content-type')
        );

        $csv = $this->actingAs($this->user)
            ->get(route('reports.export.company-tax.csv', ['fy' => 2026]));
        $csv->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
    }
}
