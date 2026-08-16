<?php

namespace Tests\Feature\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\Payment;
use App\Models\ReconciliationHistory;
use App\Models\Supplier;
use App\Services\ReconciliationService;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Seed the minimum IFRS prerequisites required for posting a transaction:
     * currency, entity (with that currency + a reporting period), the three
     * accounts the posting code touches (320 bank, 4100 revenue, 2200 GST
     * Payable), and the GST 10% Vat wired to the GST Payable account.
     *
     * Without this, postToIFRS() no-ops on the missing-account guard and the
     * test would assert nothing about the ledger.
     */
    protected function seedIfrs(): Entity
    {
        $entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 1,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $entity->id,
        ]);
        $entity->update(['currency_id' => $currency->id]);
        $entity->refresh();

        // Reporting period for the current year (required by Transaction save).
        ReportingPeriod::create([
            'period_count' => 1,
            'calendar_year' => (int) date('Y'),
            'status' => ReportingPeriod::OPEN,
            'entity_id' => $entity->id,
        ]);

        $bank = Account::create([
            'name' => 'Operating Account',
            'account_type' => Account::BANK,
            'code' => 320,
            'currency_id' => $currency->id,
            'entity_id' => $entity->id,
        ]);
        $revenue = Account::create([
            'name' => 'Consulting Revenue',
            'account_type' => Account::OPERATING_REVENUE,
            'code' => 4100,
            'currency_id' => $currency->id,
            'entity_id' => $entity->id,
        ]);
        $gstPayable = Account::create([
            // CONTROL type: Vat::save rejects non-CONTROL accounts, and this
            // account backs the GST 10% Vat below.
            'name' => 'GST Payable',
            'account_type' => Account::CONTROL,
            'code' => 2200,
            'currency_id' => $currency->id,
            'entity_id' => $entity->id,
        ]);

        // Expense accounts touched by bill posting / account suggestions.
        foreach ([
            ['Travel & Accommodation', Account::OPERATING_EXPENSE, 5300],
            ['Meals & Entertainment', Account::OPERATING_EXPENSE, 5500],
            ['Office Supplies', Account::OVERHEAD_EXPENSE, 7400],
            ['Subscriptions & Licenses', Account::OVERHEAD_EXPENSE, 7500],
            ['Other Expenses', Account::OTHER_EXPENSE, 8900],
        ] as [$name, $type, $code]) {
            Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $entity->id,
            ]);
        }

        Vat::create([
            'name' => 'GST 10%',
            'code' => 'G',
            'rate' => 10,
            'account_id' => $gstPayable->id,
            'entity_id' => $entity->id,
        ]);

        return $entity;
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
        $this->seedIfrs();

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
        $this->assertNotNull($payment->ifrs_receipt_id, 'Payment should be linked to an IFRS transaction.');

        // The IFRS transaction should carry the payment date and the bank
        // account as its main account.
        $txn = DB::table('ifrs_transactions')->where('id', $payment->ifrs_receipt_id)->first();
        $this->assertNotNull($txn);
        $this->assertEquals(
            Carbon::parse('2025-07-15')->toDateString(),
            Carbon::parse($txn->transaction_date)->toDateString(),
            'Ledger transaction_date must be the payment date, not today.'
        );

        // Ledger must balance: total debits == total credits == payment amount.
        $total = (float) $payment->amount;
        $debits = (float) DB::table('ifrs_ledgers')
            ->where('transaction_id', $txn->id)
            ->where('entry_type', 'DEBIT')
            ->sum('amount');
        $credits = (float) DB::table('ifrs_ledgers')
            ->where('transaction_id', $txn->id)
            ->where('entry_type', 'CREDIT')
            ->sum('amount');
        $this->assertEquals($total, $debits, 'Bank should be debited the full tax-inclusive amount.');
        $this->assertEquals($total, $credits, 'Total credits should equal total debits.');

        // Revenue (4100) credited the net amount, GST Payable (2200) credited
        // the GST component. Net + GST must equal the tax-inclusive total.
        $gstPayable = DB::table('ifrs_accounts')->where('code', 2200)->first();
        $revenueAcc = DB::table('ifrs_accounts')->where('code', 4100)->first();

        $gstCredited = (float) DB::table('ifrs_ledgers')
            ->where('transaction_id', $txn->id)
            ->where('entry_type', 'CREDIT')
            ->where('post_account', $gstPayable->id)
            ->sum('amount');
        $revenueCredited = (float) DB::table('ifrs_ledgers')
            ->where('transaction_id', $txn->id)
            ->where('entry_type', 'CREDIT')
            ->where('post_account', $revenueAcc->id)
            ->sum('amount');

        $expectedGst = round($total * 10 / 110, 4);
        $expectedRevenue = round($total * 100 / 110, 4);
        $this->assertEqualsWithDelta($expectedGst, $gstCredited, 0.01, 'GST Payable should be credited the 10/110 component.');
        $this->assertEqualsWithDelta($expectedRevenue, $revenueCredited, 0.01, 'Revenue should be credited the net (100/110) amount.');
        $this->assertEqualsWithDelta($total, $gstCredited + $revenueCredited, 0.01, 'Net revenue + GST must equal the tax-inclusive total.');
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

    public function test_creates_bill_from_unmatched_wise_debit(): void
    {
        $supplier = $this->createSupplier(['name' => 'AWS Cloud']);

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'AWS Cloud',
            'amount' => 250.00,
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction($bankTxn, $supplier->id);

        $this->assertNotNull($bill);
        $this->assertInstanceOf(Bill::class, $bill);
        $this->assertEquals($supplier->id, $bill->supplier_id);
        $this->assertEquals(250.00, (float) $bill->total);
        // Bank amounts carry no GST breakdown — line posts GST-free.
        $this->assertEquals(0, (float) $bill->items->first()->tax_rate);

        // Verify bank transaction is marked as matched
        $bankTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $bankTxn->status);
        $this->assertEquals($bill->id, $bankTxn->matched_transaction_id);
        $this->assertEquals('bill', $bankTxn->matched_transaction_type);
    }

    public function test_cannot_create_bill_from_credit_transaction(): void
    {
        $supplier = $this->createSupplier();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction($bankTxn, $supplier->id);

        $this->assertNull($bill);
    }

    public function test_returns_null_for_invalid_supplier(): void
    {
        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            99999 // Non-existent supplier
        );

        $this->assertNull($bill);
    }

    public function test_marks_bill_as_paid_when_requested(): void
    {
        $this->seedIfrs();
        $supplier = $this->createSupplier();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'transaction_date' => Carbon::parse('2025-07-15'),
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id,
            null,
            null,
            true // Mark as paid
        );

        $this->assertNotNull($bill);
        $this->assertEquals(Bill::STATUS_PAID, $bill->fresh()->status);
        $this->assertNotNull($bill->fresh()->paid_at);

        // The paid-at-entry payment carries the IFRS journal id.
        $payment = BillPayment::where('supplier_id', $supplier->id)->first();
        $this->assertNotNull($payment);
        $this->assertNotNull($payment->ifrs_payment_id);
    }

    public function test_does_not_mark_bill_as_paid_when_disabled(): void
    {
        $supplier = $this->createSupplier();

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction(
            $bankTxn,
            $supplier->id,
            null,
            null,
            false // Don't mark as paid
        );

        $this->assertNotNull($bill);
        $this->assertEquals(Bill::STATUS_DRAFT, $bill->fresh()->status);
        $this->assertEquals(0, BillPayment::count());
    }

    public function test_auto_create_purchases_for_all_unmatched_debits(): void
    {
        $supplier1 = $this->createSupplier(['name' => 'Google Cloud']);
        $supplier2 = $this->createSupplier(['name' => 'AWS']);

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

        $results = $this->service->autoCreatePurchases(false);

        $this->assertEquals(2, $results['count']);
        $this->assertEquals(1, $results['skipped']);
        $this->assertCount(2, $results['created']);

        // Verify all bills were created
        $this->assertDatabaseHas('bills', [
            'supplier_id' => $supplier1->id,
            'total' => 100.00,
        ]);
        $this->assertDatabaseHas('bills', [
            'supplier_id' => $supplier2->id,
            'total' => 200.00,
        ]);
    }

    public function test_finds_supplier_by_merchant_name(): void
    {
        $supplier = $this->createSupplier(['name' => 'Microsoft Corporation']);

        $bankTxn = $this->createBankTransaction([
            'type' => BankTransaction::TYPE_DEBIT,
            'merchant_name' => 'Microsoft Corporation',
        ]);

        $results = $this->service->autoCreatePurchases(false);

        $this->assertEquals(1, $results['count']);
        $this->assertDatabaseHas('bills', ['supplier_id' => $supplier->id]);
    }

    public function test_suggests_expense_account_based_on_merchant(): void
    {
        $this->seedIfrs();

        $accounts = Account::whereIn('code', [7500, 5300, 5500, 7400, 8900])->get()->keyBy('code');

        $this->assertEquals($accounts[7500]->id, $this->service->suggestExpenseAccount('AWS Cloud'));
        $this->assertEquals($accounts[7500]->id, $this->service->suggestExpenseAccount('Google Workspace'));
        $this->assertEquals($accounts[7500]->id, $this->service->suggestExpenseAccount('Microsoft Azure'));
        $this->assertEquals($accounts[5300]->id, $this->service->suggestExpenseAccount('Qantas Airlines'));
        $this->assertEquals($accounts[5300]->id, $this->service->suggestExpenseAccount('Uber Trip'));
        $this->assertEquals($accounts[5500]->id, $this->service->suggestExpenseAccount('Starbucks Coffee'));
        $this->assertEquals($accounts[7400]->id, $this->service->suggestExpenseAccount('Officeworks'));
        $this->assertEquals($accounts[8900]->id, $this->service->suggestExpenseAccount('Random Merchant'));
    }

    protected function createSupplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'name' => 'Test Supplier',
            'email' => 'supplier@example.com',
        ], $attributes));
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
