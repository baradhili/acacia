<?php

namespace Tests\Feature\Payroll;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EntitySetting;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\IfrsPosting;
use App\Services\PayrollService;
use App\Services\PsiService;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PSI assessment (the .zcode wages_and_psi spec, modules C/E): the
 * 80% rule over time-entry-backed (service work) income, the PSB
 * Results Test gating PSI mode, and the attribution of net PSI to
 * the individual after wages promptly paid.
 */
class PsiTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected PsiService $psi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00'));

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class);

        $this->entity = IfrsPosting::resolveEntity();
        $this->psi = app(PsiService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    protected function admin(): User
    {
        return tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('admin');
    }

    /** A time-entry-backed invoice: service work by construction. */
    protected function serviceInvoice(Client $client, float $subtotal, string $date = '2026-08-15'): Invoice
    {
        $entry = TimeEntry::create([
            'user_id' => User::factory()->create()->id,
            'client_id' => $client->id,
            'entry_date' => $date,
            'hours' => 10,
            'rate' => $subtotal / 10,
            'billable' => true,
            'description' => 'Consulting',
            'status' => 'approved',
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-'.uniqid(),
            'client_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'issue_date' => $date,
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'total' => $subtotal,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'time_entry_id' => $entry->id,
            'description' => 'Consulting',
            'quantity' => 10,
            'unit_price' => $subtotal / 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => $subtotal,
        ]);

        return $invoice;
    }

    public function test_the_80_rule_tracks_service_work_income_by_client(): void
    {
        $main = Client::factory()->create(['name' => 'Main Client']);
        $other = Client::factory()->create(['name' => 'Other Client']);

        $this->serviceInvoice($main, 8500);
        $this->serviceInvoice($other, 1500);

        // A product invoice (no time entries) never counts.
        Invoice::create([
            'invoice_number' => 'INV-PROD',
            'client_id' => $main->id,
            'status' => Invoice::STATUS_PAID,
            'issue_date' => '2026-08-20',
            'subtotal' => 9000,
            'tax_amount' => 0,
            'total' => 9000,
        ]);

        $income = $this->psi->incomeByClient($this->entity);

        $this->assertEquals(10000.0, $income['total']);
        $this->assertCount(2, $income['rows']);
        $this->assertSame('Main Client', $income['rows'][0]['client']);
        $this->assertEquals(0.85, $income['rows'][0]['share']);
        $this->assertTrue($income['breaches_80']);
    }

    public function test_a_spread_of_clients_does_not_breach(): void
    {
        $a = Client::factory()->create(['name' => 'Client A']);
        $b = Client::factory()->create(['name' => 'Client B']);

        $this->serviceInvoice($a, 6000);
        $this->serviceInvoice($b, 4000);

        $this->assertFalse($this->psi->incomeByClient($this->entity)['breaches_80']);
    }

    public function test_the_results_test_gates_psi_mode(): void
    {
        // Failing any of the three locks PSI mode.
        $setting = $this->psi->recordResultsTest($this->entity, [
            'specific_result' => true,
            'own_equipment' => true,
            'liable_for_defects' => false,
        ]);

        $this->assertTrue($setting->psi_mode);
        $this->assertFalse($setting->psb_results['passes']);

        // All three pass: a personal services business, not PSI mode.
        $setting = $this->psi->recordResultsTest($this->entity, [
            'specific_result' => true,
            'own_equipment' => true,
            'liable_for_defects' => true,
        ]);

        $this->assertFalse($setting->psi_mode);
    }

    public function test_attribution_nets_wages_paid_to_psi_workers(): void
    {
        $main = Client::factory()->create(['name' => 'Main Client']);
        $this->serviceInvoice($main, 8500);
        $this->serviceInvoice(Client::factory()->create(['name' => 'Other Client']), 1500);

        $worker = Employee::create([
            'entity_id' => $this->entity->id,
            'name' => 'The Worker',
            'employment_type' => Employee::TYPE_DIRECTOR,
            'payment_basis' => Employee::BASIS_SALARY,
            'annual_salary' => 120000,
            'is_personal_services' => true,
        ]);

        $run = app(PayrollService::class)->createRun([
            'frequency' => 'monthly',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'payment_date' => '2026-08-31',
        ]);
        app(PayrollService::class)->addPayslip($run, $worker);

        $attribution = $this->psi->attribution($this->entity);

        $this->assertEquals(10000.0, $attribution['psi_income']);
        $this->assertEquals(10000.0, $attribution['wages_paid']);
        $this->assertEquals(0.0, $attribution['net_psi']);
    }

    public function test_the_psi_screen_renders_the_assessment(): void
    {
        $main = Client::factory()->create(['name' => 'Main Client']);
        $this->serviceInvoice($main, 9000);

        $this->actingAs($this->admin())
            ->get('/psi')
            ->assertOk()
            ->assertSee('Personal Services Income')
            ->assertSee('Main Client')
            ->assertSee('BREACHED')
            ->assertSee('PSB Results Test');

        $this->actingAs($this->admin())
            ->post(route('psi.assess'), ['answers' => [
                'specific_result' => '1',
                'own_equipment' => '1',
                'liable_for_defects' => '0',
            ]])
            ->assertRedirect();

        $this->assertTrue(EntitySetting::forEntity($this->entity)->psi_mode);
    }
}
