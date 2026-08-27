<?php

namespace Tests\Feature\Accounting;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Supplier;
use App\Services\FiscalYearService;
use Database\Seeders\IFRSSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use IFRS\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosedPeriodEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;
    protected FiscalYearService $service;
    protected int $closedYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(IFRSSeeder::class);
        $this->actingAs(\App\Models\User::where('email', 'admin@example.com')->first());

        $this->entity = Entity::first();
        $this->service = new FiscalYearService();
        $this->closedYear = $this->service->currentYear($this->entity) - 1;

        // Close the prior year up front (empty P&L is fine — the guards
        // under test care about the period status, not the entries).
        $this->service->close($this->entity, $this->closedYear, force: true);
    }

    public function test_payment_store_rejects_closed_year_date(): void
    {
        $client = Client::factory()->create();

        $this->post('/payments', [
            'client_id' => $client->id,
            'amount' => 100,
            'payment_date' => $this->closedYear . '-09-15',
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'no',
        ])->assertSessionHasErrors('payment_date');

        $this->assertDatabaseMissing('payments', [
            'client_id' => $client->id,
            'amount' => 100,
        ]);
    }

    public function test_payment_store_accepts_open_year_date(): void
    {
        $client = Client::factory()->create();

        $this->post('/payments', [
            'client_id' => $client->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'no',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('payments', [
            'client_id' => $client->id,
            'amount' => 100,
        ]);
    }

    public function test_payment_update_rejects_closed_year_date(): void
    {
        $client = Client::factory()->create();
        $payment = Payment::create([
            'client_id' => $client->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->patch("/payments/{$payment->id}", [
            'client_id' => $client->id,
            'amount' => 150,
            'payment_date' => $this->closedYear . '-08-01',
            'payment_method' => 'bank_transfer',
            'notes' => 'backdated',
        ])->assertSessionHasErrors('payment_date');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'amount' => 100,
        ]);
    }

    public function test_bill_payment_store_rejects_closed_year_date(): void
    {
        $supplier = Supplier::factory()->create();

        $this->post('/bill-payments', [
            'supplier_id' => $supplier->id,
            'amount' => 200,
            'payment_date' => $this->closedYear . '-11-30',
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'no',
        ])->assertSessionHasErrors('payment_date');

        $this->assertDatabaseMissing('bill_payments', [
            'supplier_id' => $supplier->id,
            'amount' => 200,
        ]);
    }

    public function test_invoice_record_payment_rejects_closed_year_date(): void
    {
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
            'unit_price' => 500,
            'tax_rate' => 0,
        ]);
        $invoice->refresh()->recalculateTotals();

        $this->post("/invoices/{$invoice->id}/record-payment", [
            'amount' => 100,
            'payment_date' => $this->closedYear . '-12-01',
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasErrors('payment_date');

        $this->assertDatabaseMissing('payments', ['client_id' => $client->id, 'amount' => 100]);
    }

    public function test_bill_payment_void_blocked_in_closed_year(): void
    {
        // The payment predates the close (rows may exist from before) —
        // voiding it would need a ledger reversal inside the closed year.
        $supplier = Supplier::factory()->create();
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'amount' => 300,
            'payment_date' => $this->closedYear . '-10-05',
            'payment_method' => 'bank_transfer',
        ]);

        $this->post("/bill-payments/{$payment->id}/void")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('bill_payments', [
            'id' => $payment->id,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);
    }

    public function test_unapply_blocked_in_closed_year(): void
    {
        $supplier = Supplier::factory()->create();
        $bill = Bill::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'bill_date' => $this->closedYear . '-09-01',
            'due_date' => $this->closedYear . '-10-01',
            'status' => Bill::STATUS_OPEN,
        ]);
        $bill->items()->create([
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => 400,
            'tax_rate' => 0,
        ]);
        $bill->refresh()->recalculateTotals();

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'amount' => 400,
            'payment_date' => $this->closedYear . '-10-05',
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 400);

        $this->post("/bills/{$bill->id}/payments/{$payment->id}/unapply")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('bill_payment_allocations', [
            'bill_payment_id' => $payment->id,
            'bill_id' => $bill->id,
            'amount' => 400,
        ]);
    }
}
