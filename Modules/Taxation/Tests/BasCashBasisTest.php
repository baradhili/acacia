<?php

namespace Modules\Taxation\Tests;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\User;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The BAS figures tie to posted payment amounts only, and the GST
 * report screen renders — moved from Tests\Feature\IfrsReportsFinancialTest
 * when the reports split into core and the Taxation module.
 */
class BasCashBasisTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Entity $entity;

    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);

        // Australian entity: financial year runs 1 July – 30 June
        $this->entity = Entity::create([
            'name' => 'AU Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 7,
        ]);

        $this->currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $this->currency->id]);
        $this->entity->refresh();

        $this->user = User::factory()->create();
        $this->user->entity_id = $this->entity->id;
        $this->user->save();
        $this->user->assignRole('admin');
        $this->actingAs($this->user);

        Account::create([
            'name' => 'Operating Account',
            'account_type' => Account::BANK,
            'code' => 320,
            'currency_id' => $this->currency->id,
            'entity_id' => $this->entity->id,
        ]);

        // Annual FY reporting period, as the seeder + getReportingPeriod()
        // create it.
        ReportingPeriod::firstOrCreate(
            [
                'entity_id' => $this->entity->id,
                'calendar_year' => ReportingPeriod::year(now(), $this->entity),
            ],
            ['period_count' => 1, 'status' => ReportingPeriod::OPEN],
        );
    }

    public function test_bas_uses_posted_payment_amounts(): void
    {
        // Posting prerequisites beyond setUp's bank account: revenue +
        // GST Payable for receipts, the default expense fallback + GST
        // Receivable for supplier payments.
        $byCode = [];
        foreach ([
            ['Consulting Revenue', Account::OPERATING_REVENUE, 4100],
            ['GST Payable', Account::CONTROL, 2200],
            ['GST Receivable', Account::CONTROL, 430],
            ['Other Expenses', Account::OTHER_EXPENSE, 8900],
        ] as [$name, $type, $code]) {
            $byCode[$code] = Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $this->currency->id,
                'entity_id' => $this->entity->id,
            ]);
        }
        Vat::create([
            'name' => 'GST 10%', 'code' => 'G', 'rate' => 10,
            'account_id' => $byCode[2200]->id, 'entity_id' => $this->entity->id,
        ]);
        Vat::create([
            'name' => 'GST Input 10%', 'code' => 'I', 'rate' => 10,
            'account_id' => $byCode[430]->id, 'entity_id' => $this->entity->id,
        ]);

        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100, // +10 GST = 110 tax-inclusive item total
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        $payment = Payment::createWithUniqueNumber([
            'client_id' => $client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, 110);
        $this->assertNotNull($payment->postToIFRS(), $payment->lastPostingError ?? 'posting failed');

        $supplier = Supplier::create(['name' => 'Tax Supplier Co']);
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Bill::STATUS_OPEN,
        ]);
        $bill->items()->create([
            'description' => 'Supplies',
            'quantity' => 1,
            'unit_price' => 110, // GST-inclusive: 100 net + 10 GST
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();

        $billPayment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $billPayment->allocateToBill($bill, 110);
        $this->assertNotNull($billPayment->postToIFRS(), $billPayment->lastPostingError ?? 'posting failed');

        $response = $this->get(route('reports.bas'));

        $response->assertStatus(200);
        // GST collected and GST paid both 10.00; net payable zero
        $response->assertSee('10.00');
        $response->assertSee('0.00');
    }

    public function test_gst_report_renders(): void
    {
        $this->get(route('reports.gst'))->assertStatus(200);
    }
}
