<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ClientStatementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientStatementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ClientStatementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ClientStatementService();
    }

    protected function createClient(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Test Client',
            'email' => 'test@example.com',
        ], $attributes));
    }

    public function test_generates_statement_for_client_with_invoices(): void
    {
        $client = $this->createClient();

        Invoice::create([
            'client_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'issue_date' => Carbon::parse('2025-07-15'),
            'due_date' => Carbon::parse('2025-08-15'),
            'total' => 1000.00,
            'subtotal' => 1000.00,
            'tax_amount' => 0,
        ]);

        $statement = $this->service->generateStatement($client, Carbon::parse('2025-07-31'));

        $this->assertEquals($client->id, $statement['client']->id);
        $this->assertEquals('July 2025', $statement['period_label']);
        $this->assertEquals(1000.00, $statement['total_invoiced']);
        $this->assertCount(1, $statement['line_items']);
    }

    public function test_statement_opening_balance_is_zero_for_new_client(): void
    {
        $client = $this->createClient();

        $statement = $this->service->generateStatement($client, Carbon::parse('2025-07-31'));

        $this->assertEquals(0, $statement['opening_balance']);
    }

    public function test_statement_closing_balance_reflects_outstanding(): void
    {
        $client = $this->createClient();

        Invoice::create([
            'client_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'issue_date' => Carbon::parse('2025-07-15'),
            'due_date' => Carbon::parse('2025-08-15'),
            'total' => 1500.00,
            'subtotal' => 1500.00,
            'tax_amount' => 0,
        ]);

        $statement = $this->service->generateStatement($client, Carbon::parse('2025-07-31'));

        $this->assertEquals(1500.00, $statement['closing_balance']);
    }

    public function test_statement_includes_payments_in_period(): void
    {
        $client = $this->createClient();

        Invoice::create([
            'client_id' => $client->id,
            'status' => Invoice::STATUS_PARTIALLY_PAID,
            'issue_date' => Carbon::parse('2025-07-10'),
            'due_date' => Carbon::parse('2025-08-10'),
            'total' => 1000.00,
            'subtotal' => 1000.00,
            'tax_amount' => 0,
        ]);

        Payment::create([
            'client_id' => $client->id,
            'amount' => 400.00,
            'payment_date' => Carbon::parse('2025-07-20'),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $statement = $this->service->generateStatement($client, Carbon::parse('2025-07-31'));

        $this->assertEquals(1000.00, $statement['total_invoiced']);
        $this->assertEquals(400.00, $statement['total_paid']);
        $this->assertEquals(600.00, $statement['closing_balance']);
    }

    public function test_get_clients_with_outstanding_balances(): void
    {
        $client1 = $this->createClient(['name' => 'Client 1']);
        $client2 = $this->createClient(['name' => 'Client 2']);

        Invoice::create([
            'client_id' => $client1->id,
            'status' => Invoice::STATUS_SENT,
            'issue_date' => Carbon::now(),
            'due_date' => Carbon::now()->addDays(30),
            'total' => 1000.00,
            'subtotal' => 1000.00,
            'tax_amount' => 0,
        ]);

        Invoice::create([
            'client_id' => $client2->id,
            'status' => Invoice::STATUS_PAID,
            'issue_date' => Carbon::now(),
            'due_date' => Carbon::now()->addDays(30),
            'total' => 2000.00,
            'subtotal' => 2000.00,
            'tax_amount' => 0,
        ]);

        $clients = $this->service->getClientsWithOutstandingBalances();

        $this->assertCount(1, $clients);
        $this->assertEquals($client1->id, $clients->first()->id);
    }

    public function test_statement_line_items_sorted_by_date(): void
    {
        $client = $this->createClient();

        Invoice::create([
            'client_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'issue_date' => Carbon::parse('2025-07-20'),
            'due_date' => Carbon::parse('2025-08-20'),
            'total' => 1000.00,
            'subtotal' => 1000.00,
            'tax_amount' => 0,
        ]);

        Payment::create([
            'client_id' => $client->id,
            'amount' => 500.00,
            'payment_date' => Carbon::parse('2025-07-25'),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $statement = $this->service->generateStatement($client, Carbon::parse('2025-07-31'));

        $this->assertCount(2, $statement['line_items']);
        $this->assertEquals('invoice', $statement['line_items'][0]['type']);
        $this->assertEquals('payment', $statement['line_items'][1]['type']);
    }

    public function test_statement_excludes_pending_payments(): void
    {
        $client = $this->createClient();

        Invoice::create([
            'client_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'issue_date' => Carbon::parse('2025-07-15'),
            'due_date' => Carbon::parse('2025-08-15'),
            'total' => 1000.00,
            'subtotal' => 1000.00,
            'tax_amount' => 0,
        ]);

        Payment::create([
            'client_id' => $client->id,
            'amount' => 300.00,
            'payment_date' => Carbon::parse('2025-07-20'),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_PENDING,
        ]);

        $statement = $this->service->generateStatement($client, Carbon::parse('2025-07-31'));

        $this->assertEquals(0, $statement['total_paid']);
        $this->assertEquals(1000.00, $statement['closing_balance']);
    }
}
