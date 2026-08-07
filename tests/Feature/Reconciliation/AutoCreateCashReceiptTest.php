<?php

namespace Tests\Feature\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\ReconciliationHistory;
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

    // ========================
    // Auto-Create Purchase Tests (Task 2)
    // ========================

    public function test_creates_expense_from_unmatched_wise_debit(): void
    {
        $supplier = $this->createClient(['name' => 'AWS Cloud']);

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'AWS Cloud',
            'amount' => 250.00,
        ]);

        $expense = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id,
            'software'
        );

        $this->assertNotNull($expense);
        $this->assertInstanceOf(Expense::class, $expense);
        $this->assertEquals($supplier->id, $expense->supplier_id);
        $this->assertEquals(250.00, $expense->total);
        $this->assertEquals('software', $expense->category);

        // Verify bank transaction is marked as matched
        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $bankTxn->status);
        $this->assertEquals($expense->id, $bankTxn->matched_transaction_id);
    }

    public function test_cannot_create_expense_from_credit_transaction(): void
    {
        $supplier = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $expense = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id
        );

        $this->assertNull($expense);
    }

    public function test_returns_null_for_invalid_supplier(): void
    {
        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
        ]);

        $expense = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            99999 // Non-existent supplier
        );

        $this->assertNull($expense);
    }

    public function test_marks_expense_as_paid_when_requested(): void
    {
        $supplier = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'transaction_date' => Carbon::parse('2025-07-15'),
        ]);

        $expense = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id,
            'software',
            null,
            true // Mark as paid
        );

        $this->assertNotNull($expense);
        $this->assertEquals(Expense::STATUS_PAID, $expense->status);
        $this->assertNotNull($expense->ifrs_transaction_id);
    }

    public function test_does_not_mark_expense_as_paid_when_disabled(): void
    {
        $supplier = $this->createClient();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
        ]);

        $expense = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id,
            'software',
            null,
            false // Don't mark as paid
        );

        $this->assertNotNull($expense);
        $this->assertEquals(Expense::STATUS_DRAFT, $expense->status);
    }

    public function test_auto_create_purchases_for_all_unmatched_debits(): void
    {
        $supplier1 = $this->createClient(['name' => 'Google Cloud']);
        $supplier2 = $this->createClient(['name' => 'AWS']);

        // Create unmatched debit transactions
        $txn1 = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'Google Cloud',
            'source_id' => 'WISE-DEBIT-001',
            'amount' => 100.00,
        ]);
        $txn2 = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'AWS',
            'source_id' => 'WISE-DEBIT-002',
            'amount' => 200.00,
        ]);
        // This one won't have a matching supplier
        $txn3 = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'Unknown Merchant',
            'source_id' => 'WISE-DEBIT-003',
            'amount' => 50.00,
        ]);

        $results = $this->service->autoCreatePurchases();

        $this->assertEquals(2, $results['count']);
        $this->assertEquals(1, $results['skipped']);
        $this->assertCount(2, $results['created']);

        // Verify all expenses were created
        $this->assertDatabaseHas('expenses', [
            'supplier_id' => $supplier1->id,
            'total' => 100.00,
        ]);
        $this->assertDatabaseHas('expenses', [
            'supplier_id' => $supplier2->id,
            'total' => 200.00,
        ]);
    }

    public function test_finds_supplier_by_merchant_name(): void
    {
        $supplier = $this->createClient(['name' => 'Microsoft Corporation']);

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'Microsoft Corporation',
        ]);

        $results = $this->service->autoCreatePurchases();

        $this->assertEquals(1, $results['count']);
        $this->assertDatabaseHas('expenses', ['supplier_id' => $supplier->id]);
    }

    public function test_suggests_expense_category_based_on_merchant(): void
    {
        $this->assertEquals('software', $this->service->suggestExpenseCategory('AWS Cloud'));
        $this->assertEquals('software', $this->service->suggestExpenseCategory('Google Workspace'));
        $this->assertEquals('software', $this->service->suggestExpenseCategory('Microsoft Azure'));
        $this->assertEquals('travel', $this->service->suggestExpenseCategory('Qantas Airlines'));
        $this->assertEquals('travel', $this->service->suggestExpenseCategory('Uber Trip'));
        $this->assertEquals('meals', $this->service->suggestExpenseCategory('Starbucks Coffee'));
        $this->assertEquals('office_supplies', $this->service->suggestExpenseCategory('Officeworks'));
        $this->assertEquals('other', $this->service->suggestExpenseCategory('Random Merchant'));
    }

    // ========================
    // Manual Override Tests (Task 3)
    // ========================

    public function test_manual_override_link_to_invoice(): void
    {
        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_CREDIT,
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        // Note: Invoice creation would require IFRS setup, so we test the link mechanism
        // by mocking the existence of an invoice
        $result = $this->service->manualOverrideLink(
            $bankTxn,
            'invoice',
            999,
            'Manual link test'
        );

        // This will fail because invoice 999 doesn't exist, but we can test the validation
        $this->assertFalse($result);
    }

    public function test_manual_override_fails_for_invalid_transaction_type(): void
    {
        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_CREDIT,
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $result = $this->service->manualOverrideLink(
            $bankTxn,
            'invalid_type',
            1
        );

        $this->assertFalse($result);
    }

    public function test_manual_override_fails_for_already_matched_transaction(): void
    {
        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_CREDIT,
            'status' => BankTransaction::STATUS_MATCHED,
            'matched_transaction_id' => 123,
            'matched_transaction_type' => 'payment',
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
            'type' => BankTransaction::TYPE_CREDIT,
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

    public function test_unlink_fails_for_unmatched_transaction(): void
    {
        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_CREDIT,
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $result = $this->service->unlinkTransaction($bankTxn);

        $this->assertFalse($result);
    }

    public function test_get_available_transactions_for_linking(): void
    {
        $bankTxn = $this->createBankTransaction([
            'amount' => 1500.00,
            'transaction_date' => Carbon::parse('2025-07-15'),
        ]);

        // This will return empty since there are no matching transactions in test DB
        $transactions = $this->service->getAvailableTransactionsForLinking($bankTxn);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $transactions);
    }

    // ========================
    // Ignore Transaction Tests (Task 4)
    // ========================

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
            'matched_transaction_type' => 'payment',
        ]);

        $result = $this->service->ignoreTransaction($bankTxn, 'Wrong match');

        $this->assertFalse($result);
    }

    public function test_ignore_fails_for_already_ignored_transaction(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_IGNORED,
            'notes' => 'Already ignored',
        ]);

        $result = $this->service->ignoreTransaction($bankTxn, 'Try to ignore again');

        $this->assertFalse($result);
    }

    public function test_ignore_multiple_transactions(): void
    {
        $txn1 = $this->createBankTransaction(['source_id' => 'WISE-IGN-1']);
        $txn2 = $this->createBankTransaction(['source_id' => 'WISE-IGN-2']);
        $txn3 = $this->createBankTransaction(['source_id' => 'WISE-IGN-3']);

        $results = $this->service->ignoreTransactions(
            [$txn1->id, $txn2->id, $txn3->id],
            'Batch ignore test'
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
            'notes' => 'Previously ignored',
        ]);

        $result = $this->service->restoreIgnoredTransaction($bankTxn);

        $this->assertTrue($result);
        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_PENDING, $bankTxn->status);
        $this->assertStringContainsString('Restored', $bankTxn->notes);
    }

    public function test_restore_fails_for_pending_transaction(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $result = $this->service->restoreIgnoredTransaction($bankTxn);

        $this->assertFalse($result);
    }

    // ========================
    // Reconciliation History Tests (Task 5)
    // ========================

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

    public function test_get_history_returns_records_for_transaction(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        // Create some history records
        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_AUTO_MATCH,
            'status' => ReconciliationHistory::STATUS_FAILED,
            'details' => 'No match found',
        ]);
        ReconciliationHistory::create([
            'bank_transaction_id' => $bankTxn->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
            'details' => 'Ignored',
        ]);

        $history = $this->service->getHistory($bankTxn);

        $this->assertCount(2, $history);
        $this->assertEquals(ReconciliationHistory::ACTION_IGNORE, $history->first()->action);
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
        $this->assertArrayHasKey('auto_match', $stats['by_action']);
        $this->assertArrayHasKey('ignore', $stats['by_action']);
    }

    public function test_history_records_have_correct_linked_transaction(): void
    {
        $bankTxn = $this->createBankTransaction([
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $this->service->manualOverrideLink(
            $bankTxn,
            'payment',
            456,
            'Manual link'
        );

        $history = ReconciliationHistory::where('bank_transaction_id', $bankTxn->id)
            ->where('action', ReconciliationHistory::ACTION_MANUAL_MATCH)
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals(456, $history->linked_transaction_id);
        $this->assertEquals('payment', $history->linked_transaction_type);
    }
}
