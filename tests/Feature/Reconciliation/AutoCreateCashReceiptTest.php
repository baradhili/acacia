<?php

namespace Tests\Feature\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Payment;
use App\Services\ReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoCreateCashReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected ReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReconciliationService();
    }

    protected function createClient(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Test Client',
            'email' => 'test@example.com',
        ], $attributes));
    }

    protected function createBankTransaction(array $attributes = []): BankTransaction
    {
        return BankTransaction::create(array_merge([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-' . uniqid(),
            'reference' => 'INV-2025-0001',
            'description' => 'Test payment from client',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::now(),
            'status' => BankTransaction::STATUS_PENDING,
        ], $attributes));
    }

    public function test_creates_cash_receipt_from_unmatched_wise_credit(): void
    {
        $client = $this->createClient(['name' => 'Acme Corp']);

        $bankTxn = $this->createBankTransaction([
            'payer_name' => 'Acme Corp',
            'reference' => 'INV-2025-0001',
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id
        );

        $this->assertNotNull($payment);
        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertEquals($client->id, $payment->client_id);
        $this->assertEquals(1500.00, $payment->amount);
        $this->assertEquals(Payment::STATUS_COMPLETED, $payment->status);
        $this->assertEquals(Payment::METHOD_BANK_TRANSFER, $payment->payment_method);

        // Verify bank transaction is marked as matched
        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $bankTxn->status);
        $this->assertEquals($payment->id, $bankTxn->matched_transaction_id);
    }

    public function test_cannot_create_cash_receipt_from_debit_transaction(): void
    {
        $client = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id
        );

        $this->assertNull($payment);
    }

    public function test_returns_null_for_invalid_client(): void
    {
        $bankTxn = $this->createBankTransaction();

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            99999 // Non-existent client
        );

        $this->assertNull($payment);
    }

    public function test_posts_payment_to_ifrs_when_enabled(): void
    {
        $client = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'transaction_date' => Carbon::parse('2025-07-15'),
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id,
            null,
            true // Post to IFRS
        );

        $this->assertNotNull($payment);
        $this->assertNotNull($payment->ifrs_receipt_id);
    }

    public function test_does_not_post_to_ifrs_when_disabled(): void
    {
        $client = $this->createClient();

        $bankTxn = $this->createBankTransaction();

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id,
            null,
            false // Don't post to IFRS
        );

        $this->assertNotNull($payment);
        $this->assertNull($payment->ifrs_receipt_id);
    }

    public function test_auto_create_cash_receipts_for_all_unmatched_credits(): void
    {
        $client1 = $this->createClient(['name' => 'Client One']);
        $client2 = $this->createClient(['name' => 'Client Two']);

        // Create unmatched credit transactions
        $txn1 = $this->createBankTransaction([
            'payer_name' => 'Client One',
            'source_id' => 'WISE-001',
        ]);
        $txn2 = $this->createBankTransaction([
            'payer_name' => 'Client Two',
            'source_id' => 'WISE-002',
        ]);
        // This one won't have a matching client
        $txn3 = $this->createBankTransaction([
            'payer_name' => 'Unknown Client',
            'source_id' => 'WISE-003',
        ]);

        $results = $this->service->autoCreateCashReceipts();

        $this->assertEquals(2, $results['count']);
        $this->assertEquals(1, $results['skipped']);
        $this->assertCount(2, $results['created']);

        // Verify all payments were created
        $this->assertDatabaseHas('payments', [
            'client_id' => $client1->id,
            'amount' => 1500.00,
        ]);
        $this->assertDatabaseHas('payments', [
            'client_id' => $client2->id,
            'amount' => 1500.00,
        ]);

        // Verify bank transactions are marked as matched
        $txn1->refresh();
        $txn2->refresh();
        $txn3->refresh();
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $txn1->status);
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $txn2->status);
        $this->assertEquals(BankTransaction::STATUS_PENDING, $txn3->status); // Not matched
    }

    public function test_auto_create_cash_receipts_filters_by_client(): void
    {
        $client1 = $this->createClient(['name' => 'Target Client']);
        $client2 = $this->createClient(['name' => 'Other Client']);

        // Create transactions for both clients
        $txn1 = $this->createBankTransaction([
            'payer_name' => 'Target Client',
            'source_id' => 'WISE-001',
        ]);
        $txn2 = $this->createBankTransaction([
            'payer_name' => 'Other Client',
            'source_id' => 'WISE-002',
        ]);

        // Only create receipts for client1
        $results = $this->service->autoCreateCashReceipts($client1->id);

        $this->assertEquals(1, $results['count']);
        $this->assertDatabaseHas('payments', ['client_id' => $client1->id]);
        $this->assertDatabaseMissing('payments', ['client_id' => $client2->id]);
    }

    public function test_finds_client_by_payer_name(): void
    {
        $client = $this->createClient(['name' => 'ACME Corporation']);

        $bankTxn = $this->createBankTransaction([
            'payer_name' => 'ACME Corporation',
        ]);

        // Use autoCreateCashReceipts which tries to find client automatically
        $results = $this->service->autoCreateCashReceipts();

        $this->assertEquals(1, $results['count']);
        $this->assertDatabaseHas('payments', ['client_id' => $client->id]);
    }

    public function test_sets_correct_payment_date_from_bank_transaction(): void
    {
        $client = $this->createClient();
        $transactionDate = Carbon::parse('2025-07-15');

        $bankTxn = $this->createBankTransaction([
            'transaction_date' => $transactionDate,
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id
        );

        $this->assertEquals($transactionDate->toDateString(), $payment->payment_date->toDateString());
    }

    public function test_includes_wise_transaction_reference_in_payment(): void
    {
        $client = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'source_id' => 'WISE-12345',
            'reference' => 'INV-2025-0001',
            'description' => 'Payment for invoice',
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id
        );

        $this->assertStringContainsString('WISE-12345', $payment->notes);
        $this->assertStringContainsString('INV-2025-0001', $payment->reference);
    }
}
