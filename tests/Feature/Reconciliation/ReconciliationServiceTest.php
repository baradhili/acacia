<?php

namespace Tests\Feature\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReconciliationHistory;
use App\Models\Supplier;
use App\Services\ReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationServiceTest extends TestCase
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
            'reference' => 'REF-' . uniqid(),
            'description' => 'Test transaction',
            'amount' => 1000.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::now(),
            'status' => BankTransaction::STATUS_PENDING,
        ], $attributes));
    }

    // =====================================================
    // Cash Receipt Creation Tests
    // =====================================================

    public function test_creates_cash_receipt_from_unmatched_wise_credit(): void
    {
        $client = $this->createClient(['name' => 'Acme Corp']);

        $bankTxn = $this->createBankTransaction([
            'payer_name' => 'Acme Corp',
            'type' => BankTransaction::TYPE_CREDIT,
            'amount' => 1500.00,
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
        $this->assertNotNull($payment->payment_number);
        $this->assertStringContainsString('PAY-', $payment->payment_number);
    }

    public function test_cash_receipt_marks_bank_transaction_as_matched(): void
    {
        $client = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id
        );

        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $bankTxn->status);
        $this->assertEquals($payment->id, $bankTxn->matched_transaction_id);
        $this->assertEquals('payment', $bankTxn->matched_transaction_type);
        $this->assertNotNull($bankTxn->matched_at);
    }

    public function test_cannot_create_cash_receipt_from_debit(): void
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
        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_PENDING, $bankTxn->status);
    }

    public function test_cash_receipt_returns_null_for_invalid_client(): void
    {
        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            99999
        );

        $this->assertNull($payment);
    }

    public function test_cash_receipt_sets_correct_payment_date(): void
    {
        $client = $this->createClient();
        $transactionDate = Carbon::parse('2025-07-15');

        $bankTxn = $this->createBankTransaction([
            'transaction_date' => $transactionDate,
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id
        );

        $this->assertEquals($transactionDate->toDateString(), $payment->payment_date->toDateString());
    }

    public function test_cash_receipt_includes_transaction_reference(): void
    {
        $client = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'source_id' => 'WISE-12345',
            'reference' => 'INV-2025-0001',
            'description' => 'Test payment',
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id
        );

        $this->assertStringContainsString('WISE-12345', $payment->notes);
        $this->assertEquals('INV-2025-0001', $payment->reference);
    }

    // =====================================================
    // Auto-Create Cash Receipts Batch Tests
    // =====================================================

    public function test_auto_create_cash_receipts_processes_all_credits(): void
    {
        $client1 = $this->createClient(['name' => 'Client One']);
        $client2 = $this->createClient(['name' => 'Client Two']);

        $this->createBankTransaction([
            'payer_name' => 'Client One',
            'source_id' => 'WISE-001',
            'type' => BankTransaction::TYPE_CREDIT,
        ]);
        $this->createBankTransaction([
            'payer_name' => 'Client Two',
            'source_id' => 'WISE-002',
            'type' => BankTransaction::TYPE_CREDIT,
        ]);
        // No matching client
        $this->createBankTransaction([
            'payer_name' => 'Unknown',
            'source_id' => 'WISE-003',
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $results = $this->service->autoCreateCashReceipts();

        $this->assertEquals(2, $results['count']);
        $this->assertEquals(1, $results['skipped']);
        $this->assertCount(2, $results['created']);
        $this->assertEquals(2, Payment::count());
    }

    public function test_auto_create_cash_receipts_filters_by_client(): void
    {
        $targetClient = $this->createClient(['name' => 'Target Client']);
        $otherClient = $this->createClient(['name' => 'Other Client']);

        $this->createBankTransaction([
            'payer_name' => 'Target Client',
            'source_id' => 'WISE-001',
            'type' => BankTransaction::TYPE_CREDIT,
        ]);
        $this->createBankTransaction([
            'payer_name' => 'Other Client',
            'source_id' => 'WISE-002',
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $results = $this->service->autoCreateCashReceipts($targetClient->id);

        $this->assertEquals(1, $results['count']);
        $this->assertEquals(1, Payment::where('client_id', $targetClient->id)->count());
        $this->assertEquals(0, Payment::where('client_id', $otherClient->id)->count());
    }

    // =====================================================
    // Bill/Purchase Creation Tests
    // =====================================================

    public function test_creates_bill_from_unmatched_wise_debit(): void
    {
        $supplier = $this->createSupplier(['name' => 'AWS Cloud']);

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'AWS Cloud',
            'amount' => 250.00,
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id,
            null,
            null,
            false
        );

        $this->assertNotNull($bill);
        $this->assertInstanceOf(Bill::class, $bill);
        $this->assertEquals($supplier->id, $bill->supplier_id);
        $this->assertEquals(250.00, (float) $bill->total);
    }

    public function test_bill_marks_bank_transaction_as_matched(): void
    {
        $supplier = $this->createSupplier(['name' => 'Supplier']);

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'Supplier',
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id,
            null,
            null,
            false
        );

        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $bankTxn->status);
        $this->assertEquals($bill->id, $bankTxn->matched_transaction_id);
        $this->assertEquals('bill', $bankTxn->matched_transaction_type);
    }

    public function test_cannot_create_bill_from_credit(): void
    {
        $supplier = $this->createSupplier();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id
        );

        $this->assertNull($bill);
    }

    public function test_bill_paid_at_entry_creates_payment_without_ifrs_accounts(): void
    {
        $supplier = $this->createSupplier();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'Supplier',
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id,
            null,
            null,
            true
        );

        $this->assertEquals(Bill::STATUS_PAID, $bill->fresh()->status);
        $payment = BillPayment::where('supplier_id', $supplier->id)->first();
        $this->assertNotNull($payment);
        // No IFRS accounts seeded — posting no-ops but the payment persists.
        $this->assertNull($payment->ifrs_payment_id);
    }

    public function test_bill_not_paid_when_disabled(): void
    {
        $supplier = $this->createSupplier();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'Supplier',
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id,
            null,
            null,
            false
        );

        $this->assertEquals(Bill::STATUS_DRAFT, $bill->fresh()->status);
        $this->assertEquals(0, BillPayment::count());
    }

    protected function createSupplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'name' => 'Test Supplier',
            'email' => 'supplier@example.com',
        ], $attributes));
    }

    // =====================================================
    // Ignore Transaction Tests
    // =====================================================

    public function test_ignore_pending_transaction(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $result = $this->service->ignoreTransaction($bankTxn, 'Personal expense');

        $this->assertTrue($result);
        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_IGNORED, $bankTxn->status);
        $this->assertStringContainsString('Personal expense', $bankTxn->notes);
    }

    public function test_ignore_fails_for_matched_transaction(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_MATCHED,
            'matched_transaction_id' => 123,
        ]);

        $result = $this->service->ignoreTransaction($bankTxn, 'Try to ignore');

        $this->assertFalse($result);
        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $bankTxn->status);
    }

    public function test_ignore_fails_for_already_ignored(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_IGNORED,
        ]);

        $result = $this->service->ignoreTransaction($bankTxn, 'Ignore again');

        $this->assertFalse($result);
    }

    public function test_ignore_multiple_transactions(): void
    {
        $txn1 = $this->createBankTransaction();
        $txn2 = $this->createBankTransaction();
        $txn3 = $this->createBankTransaction();

        $results = $this->service->ignoreTransactions(
            [$txn1->id, $txn2->id, $txn3->id],
            'Batch ignore'
        );

        $this->assertEquals(3, $results['ignored']);
        $this->assertEquals(0, $results['skipped']);

        $txn1->refresh();
        $txn2->refresh();
        $txn3->refresh();
        $this->assertEquals(BankTransaction::STATUS_IGNORED, $txn1->status);
        $this->assertEquals(BankTransaction::STATUS_IGNORED, $txn2->status);
        $this->assertEquals(BankTransaction::STATUS_IGNORED, $txn3->status);
    }

    public function test_restore_ignored_transaction(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_IGNORED,
        ]);

        $result = $this->service->restoreIgnoredTransaction($bankTxn);

        $this->assertTrue($result);
        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_PENDING, $bankTxn->status);
        $this->assertStringContainsString('Restored', $bankTxn->notes);
    }

    public function test_restore_fails_for_non_ignored(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $result = $this->service->restoreIgnoredTransaction($bankTxn);

        $this->assertFalse($result);
    }

    // =====================================================
    // Manual Override Tests
    // =====================================================

    public function test_manual_override_link_to_payment(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        // Test validation - payment doesn't exist
        $result = $this->service->manualOverrideLink(
            $bankTxn,
            'payment',
            999,
            'Manual link'
        );

        $this->assertFalse($result);
    }

    public function test_manual_override_fails_for_invalid_type(): void
    {
        $bankTxn = $this->createBankTransaction();

        $result = $this->service->manualOverrideLink(
            $bankTxn,
            'invalid_type',
            1
        );

        $this->assertFalse($result);
    }

    public function test_manual_override_fails_for_already_matched(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_MATCHED,
            'matched_transaction_id' => 123,
        ]);

        $result = $this->service->manualOverrideLink(
            $bankTxn,
            'payment',
            456
        );

        $this->assertFalse($result);
    }

    public function test_unlink_matched_transaction(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_MATCHED,
            'matched_transaction_id' => 123,
            'matched_transaction_type' => 'payment',
        ]);

        $result = $this->service->unlinkTransaction($bankTxn, 'Testing unlink');

        $this->assertTrue($result);
        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_PENDING, $bankTxn->status);
        $this->assertNull($bankTxn->matched_transaction_id);
        $this->assertStringContainsString('Unlinked', $bankTxn->notes);
    }

    public function test_unlink_fails_for_pending(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $result = $this->service->unlinkTransaction($bankTxn);

        $this->assertFalse($result);
    }

    public function test_get_available_transactions_returns_collection(): void
    {
        $bankTxn = $this->createBankTransaction([
            'amount' => 1500.00,
            'transaction_date' => Carbon::parse('2025-07-15'),
        ]);

        $transactions = $this->service->getAvailableTransactionsForLinking($bankTxn);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $transactions);
    }

    // =====================================================
    // Reconciliation History Tests
    // =====================================================

    public function test_ignore_creates_history_record(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $this->service->ignoreTransaction($bankTxn, 'Personal expense');

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
    }

    public function test_unlink_creates_history_record(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_MATCHED,
            'matched_transaction_id' => 123,
            'matched_transaction_type' => 'payment',
        ]);

        $this->service->unlinkTransaction($bankTxn, 'Wrong match');

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_UNMATCH,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
            'linked_transaction_id' => 123,
            'linked_transaction_type' => 'payment',
        ]);
    }

    public function test_restore_ignored_creates_history_record(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_IGNORED,
        ]);

        $this->service->restoreIgnoredTransaction($bankTxn);

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_UNIGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
    }

    public function test_manual_override_creates_history_record(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        // This will fail because payment 999 doesn't exist, but history should be logged
        $this->service->manualOverrideLink(
            $bankTxn,
            'payment',
            999,
            'Test link'
        );

        // Should have a failed history record
        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_MANUAL_MATCH,
            'status' => ReconciliationHistory::STATUS_FAILED,
        ]);
    }

    public function test_cash_receipt_creates_history_record(): void
    {
        $client = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id
        );

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_AUTO_CREATE_RECEIPT,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
            'linked_transaction_id' => $payment->id,
            'linked_transaction_type' => 'payment',
        ]);
    }

    public function test_expense_creates_history_record(): void
    {
        $supplier = $this->createClient(['name' => 'Supplier']);

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'Supplier',
        ]);

        $expense = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id
        );

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_AUTO_CREATE_EXPENSE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
            'linked_transaction_id' => $expense->id,
            'linked_transaction_type' => 'expense',
        ]);
    }

    public function test_get_history_returns_records_for_transaction(): void
    {
        $bankTxn = $this->createBankTransaction();

        // Create multiple history records
        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_UNIGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);

        $history = $this->service->getHistory($bankTxn);

        $this->assertCount(2, $history);
    }

    public function test_get_history_stats(): void
    {
        $bankTxn = $this->createBankTransaction();

        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_AUTO_MATCH,
            'status' => ReconciliationHistory::STATUS_FAILED,
        ]);
        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);

        $stats = $this->service->getHistoryStats();

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['successful']);
        $this->assertEquals(1, $stats['failed']);
        $this->assertArrayHasKey('ignore', $stats['by_action']);
        $this->assertArrayHasKey('auto_match', $stats['by_action']);
    }

    public function test_history_records_linked_transaction_data(): void
    {
        $bankTxn = $this->createBankTransaction();

        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_MANUAL_MATCH,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
            'linked_transaction_id' => 456,
            'linked_transaction_type' => 'payment',
            'details' => 'Manual link test',
        ]);

        $history = ReconciliationHistory::where('bank_transaction_id', $bankTxn->id)->first();

        $this->assertEquals(456, $history->linked_transaction_id);
        $this->assertEquals('payment', $history->linked_transaction_type);
        $this->assertEquals('Manual link test', $history->details);
    }

    public function test_history_with_metadata(): void
    {
        $bankTxn = $this->createBankTransaction();

        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
            'metadata' => ['reason' => 'Personal', 'amount' => 100],
        ]);

        $history = ReconciliationHistory::where('bank_transaction_id', $bankTxn->id)->first();

        $this->assertIsArray($history->metadata);
        $this->assertEquals('Personal', $history->metadata['reason']);
        $this->assertEquals(100, $history->metadata['amount']);
    }

    public function test_history_query_scopes(): void
    {
        $bankTxn = $this->createBankTransaction();

        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_FAILED,
        ]);

        $this->assertEquals(2, ReconciliationHistory::forTransaction($bankTxn->id)->count());
        $this->assertEquals(2, ReconciliationHistory::byAction(ReconciliationHistory::ACTION_IGNORE)->count());
        $this->assertEquals(1, ReconciliationHistory::successful()->count());
        $this->assertEquals(1, ReconciliationHistory::failed()->count());
    }

    public function test_history_model_relationships(): void
    {
        $bankTxn = $this->createBankTransaction();

        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);

        $history = ReconciliationHistory::first();

        $this->assertNotNull($history->bankTransaction);
        $this->assertEquals($bankTxn->id, $history->bankTransaction->id);
    }

    // =====================================================
    // Tolerance Settings Tests
    // =====================================================

    public function test_get_tolerances(): void
    {
        $tolerances = $this->service->getTolerances();

        $this->assertArrayHasKey('amount_tolerance', $tolerances);
        $this->assertArrayHasKey('date_tolerance_days', $tolerances);
        $this->assertEquals(0.01, $tolerances['amount_tolerance']);
        $this->assertEquals(3, $tolerances['date_tolerance_days']);
    }

    // =====================================================
    // Bank Transaction Model Tests
    // =====================================================

    public function test_bank_transaction_scopes(): void
    {
        $pending = $this->createBankTransaction(['status' => BankTransaction::STATUS_PENDING]);
        $matched = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_MATCHED,
            'source_id' => 'WISE-MATCHED',
        ]);
        $ignored = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_IGNORED,
            'source_id' => 'WISE-IGNORED',
        ]);

        $this->assertEquals(1, BankTransaction::pending()->count());
        $this->assertEquals(1, BankTransaction::matched()->count());
        $this->assertEquals(1, BankTransaction::fromSource(BankTransaction::SOURCE_WISE)->count());
    }

    public function test_bank_transaction_is_matched(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_MATCHED,
        ]);

        $this->assertTrue($bankTxn->isMatched());
    }

    public function test_bank_transaction_client_relationship(): void
    {
        $client = $this->createClient();
        $bankTxn = $this->createBankTransaction([
            'client_id' => $client->id,
        ]);

        $this->assertNotNull($bankTxn->client);
        $this->assertEquals($client->id, $bankTxn->client->id);
    }

    public function test_mark_as_matched_updates_fields(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $bankTxn->markAsMatched(123, 'payment');

        $this->assertEquals(BankTransaction::STATUS_MATCHED, $bankTxn->status);
        $this->assertEquals(123, $bankTxn->matched_transaction_id);
        $this->assertEquals('payment', $bankTxn->matched_transaction_type);
        $this->assertNotNull($bankTxn->matched_at);
    }

    public function test_mark_as_ignored_updates_fields(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $bankTxn->markAsIgnored('Personal expense');

        $this->assertEquals(BankTransaction::STATUS_IGNORED, $bankTxn->status);
        $this->assertEquals('Personal expense', $bankTxn->notes);
    }

    // =====================================================
    // Edge Cases
    // =====================================================

    public function test_zero_amount_transaction(): void
    {
        $client = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'amount' => 0,
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id
        );

        $this->assertNotNull($payment);
        $this->assertEquals(0, $payment->amount);
    }

    public function test_decimal_amount_transaction(): void
    {
        $client = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'amount' => 123.45,
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $payment = $this->service->createCashReceiptFromBankTransaction(
            $bankTxn,
            $client->id
        );

        $this->assertNotNull($payment);
        $this->assertEquals(123.45, (float) $payment->amount);
    }

    public function test_ignore_transactions_with_invalid_ids(): void
    {
        $txn = $this->createBankTransaction();

        $results = $this->service->ignoreTransactions(
            [$txn->id, 99999, 88888],
            'Batch ignore'
        );

        $this->assertEquals(1, $results['ignored']);
        $this->assertEquals(2, $results['skipped']);
        $this->assertCount(2, $results['errors']);
    }

    public function test_history_cascade_delete_with_bank_transaction(): void
    {
        $bankTxn = $this->createBankTransaction();

        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);

        $this->assertEquals(1, ReconciliationHistory::count());

        $bankTxn->delete();

        $this->assertEquals(0, ReconciliationHistory::count());
    }
}
