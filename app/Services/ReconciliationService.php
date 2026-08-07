<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use IFRS\Models\Ledger;

class ReconciliationService
{
    // Matching tolerances
    private const AMOUNT_TOLERANCE = 0.01; // $0.01 tolerance for amount matching
    private const DATE_TOLERANCE_DAYS = 3; // 3 days tolerance for date matching

    // Default account codes
    private const DEFAULT_BANK_ACCOUNT_CODE = 320; // Operating Account
    private const DEFAULT_REVENUE_ACCOUNT_CODE = 4100; // Consulting Revenue

    /**
     * Attempt to auto-match a Wise transaction against IFRS ledgers
     */
    public function matchTransaction(BankTransaction $wiseTransaction): ?int
    {
        $matchedLedger = $this->findMatchingLedger($wiseTransaction);

        if ($matchedLedger) {
            $wiseTransaction->markAsMatched($matchedLedger->id, 'ledger');
            return $matchedLedger->id;
        }

        return null;
    }

    /**
     * Find a matching ledger entry
     */
    private function findMatchingLedger(BankTransaction $wiseTransaction): ?Ledger
    {
        $query = Ledger::query();

        // Match by reference
        $query->where('reference', 'like', '%' . $wiseTransaction->reference . '%');

        // Match by amount (with tolerance)
        $amount = $wiseTransaction->amount;
        $query->whereBetween('amount', [
            $amount - self::AMOUNT_TOLERANCE,
            $amount + self::AMOUNT_TOLERANCE,
        ]);

        // Match by date (with tolerance)
        $dateFrom = $wiseTransaction->transaction_date->copy()
            ->subDays(self::DATE_TOLERANCE_DAYS);
        $dateTo = $wiseTransaction->transaction_date->copy()
            ->addDays(self::DATE_TOLERANCE_DAYS);

        $query->whereBetween('date', [$dateFrom, $dateTo]);

        // For credits, look for debit entries and vice versa
        if ($wiseTransaction->type === BankTransaction::TYPE_CREDIT) {
            $query->where('entry_type', 'debit');
        } else {
            $query->where('entry_type', 'credit');
        }

        return $query->first();
    }

    /**
     * Auto-match all pending transactions
     */
    public function autoMatchAll(): array
    {
        $matched = 0;
        $unmatched = 0;
        $errors = [];

        $pendingTransactions = BankTransaction::pending()->get();

        foreach ($pendingTransactions as $transaction) {
            try {
                if ($this->matchTransaction($transaction)) {
                    $matched++;
                } else {
                    $unmatched++;
                }
            } catch (\Exception $e) {
                $errors[] = "Transaction {$transaction->id}: " . $e->getMessage();
                $unmatched++;
            }
        }

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'errors' => $errors,
        ];
    }

    /**
     * Manual match a Wise transaction to a ledger entry
     */
    public function manualMatch(BankTransaction $wiseTransaction, int $ledgerId, string $type = 'ledger'): bool
    {
        $wiseTransaction->markAsMatched($ledgerId, $type);
        return true;
    }

    /**
     * Get matching candidates for a Wise transaction
     */
    public function getMatchingCandidates(BankTransaction $wiseTransaction): Collection
    {
        $amount = $wiseTransaction->amount;
        $dateFrom = $wiseTransaction->transaction_date->copy()
            ->subDays(self::DATE_TOLERANCE_DAYS);
        $dateTo = $wiseTransaction->transaction_date->copy()
            ->addDays(self::DATE_TOLERANCE_DAYS);

        return Ledger::query()
            ->whereBetween('amount', [
                $amount - self::AMOUNT_TOLERANCE,
                $amount + self::AMOUNT_TOLERANCE,
            ])
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->with('account')
            ->get();
    }

    /**
     * Calculate match score between Wise transaction and ledger
     */
    public function calculateMatchScore(BankTransaction $wiseTransaction, Ledger $ledger): float
    {
        $score = 0;

        // Reference match (40% weight)
        if ($this->referencesMatch($wiseTransaction->reference, $ledger->reference)) {
            $score += 40;
        }

        // Amount match (30% weight)
        $amountDiff = abs($wiseTransaction->amount - abs($ledger->amount));
        if ($amountDiff < 0.01) {
            $score += 30;
        } elseif ($amountDiff < 1.00) {
            $score += 15;
        }

        // Date match (30% weight)
        $daysDiff = abs($wiseTransaction->transaction_date->diffInDays($ledger->date));
        if ($daysDiff === 0) {
            $score += 30;
        } elseif ($daysDiff <= self::DATE_TOLERANCE_DAYS) {
            $score += 30 - ($daysDiff * 10);
        }

        return $score;
    }

    /**
     * Check if references match
     */
    private function referencesMatch(string $wiseRef, ?string $ledgerRef): bool
    {
        if (empty($ledgerRef)) {
            return false;
        }

        // Normalize references for comparison
        $wiseRef = strtoupper(trim($wiseRef));
        $ledgerRef = strtoupper(trim($ledgerRef));

        // Exact match
        if ($wiseRef === $ledgerRef) {
            return true;
        }

        // Check if one contains the other
        if (str_contains($wiseRef, $ledgerRef) || str_contains($ledgerRef, $wiseRef)) {
            return true;
        }

        // Extract numbers and compare
        preg_match_all('/\d+/', $wiseRef, $wiseNums);
        preg_match_all('/\d+/', $ledgerRef, $ledgerNums);

        if (!empty($wiseNums[0]) && !empty($ledgerNums[0])) {
            $wiseNum = end($wiseNums[0]);
            $ledgerNum = end($ledgerNums[0]);
            if ($wiseNum === $ledgerNum) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get tolerance settings
     */
    public function getTolerances(): array
    {
        return [
            'amount_tolerance' => self::AMOUNT_TOLERANCE,
            'date_tolerance_days' => self::DATE_TOLERANCE_DAYS,
        ];
    }

    /**
     * Auto-create a cash receipt (Payment) from an unmatched Wise credit
     * 
     * @param BankTransaction $bankTransaction The unmatched credit transaction
     * @param int $clientId The client to associate with the payment
     * @param int|null $receivedByUserId The user recording the payment
     * @param bool $postToIFRS Whether to post the payment to IFRS immediately
     * @return Payment|null The created payment or null on failure
     */
    public function createCashReceiptFromBankTransaction(
        BankTransaction $bankTransaction,
        int $clientId,
        ?int $receivedByUserId = null,
        bool $postToIFRS = true
    ): ?Payment {
        // Validate this is a credit transaction
        if ($bankTransaction->type !== BankTransaction::TYPE_CREDIT) {
            Log::warning("Cannot create cash receipt from debit transaction", [
                'bank_transaction_id' => $bankTransaction->id,
                'type' => $bankTransaction->type,
            ]);
            return null;
        }

        // Validate client exists
        $client = Client::find($clientId);
        if (!$client) {
            Log::error("Client not found for cash receipt creation", [
                'client_id' => $clientId,
                'bank_transaction_id' => $bankTransaction->id,
            ]);
            return null;
        }

        try {
            // Create the payment (cash receipt)
            $payment = Payment::create([
                'payment_number' => Payment::generatePaymentNumber(),
                'client_id' => $clientId,
                'received_by' => $receivedByUserId,
                'amount' => $bankTransaction->amount,
                'payment_date' => $bankTransaction->transaction_date,
                'payment_method' => Payment::METHOD_BANK_TRANSFER,
                'reference' => $bankTransaction->reference,
                'notes' => "Auto-created from Wise transaction {$bankTransaction->source_id}. Description: {$bankTransaction->description}",
                'status' => Payment::STATUS_COMPLETED,
            ]);

            // Mark bank transaction as matched
            $bankTransaction->markAsMatched($payment->id, 'payment');

            Log::info("Cash receipt created from unmatched Wise credit", [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'client_id' => $clientId,
                'amount' => $payment->amount,
                'bank_transaction_id' => $bankTransaction->id,
            ]);

            // Optionally post to IFRS
            if ($postToIFRS) {
                $this->postPaymentToIFRS($payment);
            }

            return $payment;

        } catch (\Exception $e) {
            Log::error("Failed to create cash receipt from Wise transaction", [
                'bank_transaction_id' => $bankTransaction->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Post a payment to IFRS (Dr Cash / Cr Revenue)
     * 
     * @param Payment $payment
     * @return bool Success status
     */
    protected function postPaymentToIFRS(Payment $payment): bool
    {
        try {
            $bankAccount = \IFRS\Models\Account::where('code', self::DEFAULT_BANK_ACCOUNT_CODE)->first();
            $revenueAccount = \IFRS\Models\Account::where('code', self::DEFAULT_REVENUE_ACCOUNT_CODE)->first();

            if (!$bankAccount || !$revenueAccount) {
                Log::error('IFRS accounts not found for payment posting', [
                    'bank_code' => self::DEFAULT_BANK_ACCOUNT_CODE,
                    'revenue_code' => self::DEFAULT_REVENUE_ACCOUNT_CODE,
                ]);
                return false;
            }

            // Create journal entry
            // Dr Bank (Debit - increase asset)
            // Cr Revenue (Credit - increase income)
            $journalEntry = new \IFRS\Transactions\JournalEntry([
                'date' => $payment->payment_date,
                'narration' => "Cash receipt: {$payment->payment_number} from {$payment->client->name}",
                'reference' => $payment->payment_number,
            ]);

            $journalEntry->addLineItem(
                \IFRS\Models\LineItem::create([
                    'account_id' => $bankAccount->id,
                    'amount' => $payment->amount,
                    'type' => \IFRS\Models\LineItem::DEBIT,
                    'tax_rate' => 0,
                ])
            );

            $journalEntry->addLineItem(
                \IFRS\Models\LineItem::create([
                    'account_id' => $revenueAccount->id,
                    'amount' => $payment->amount,
                    'type' => \IFRS\Models\LineItem::CREDIT,
                    'tax_rate' => 0,
                ])
            );

            $journalEntry->save();

            // Store the IFRS receipt ID
            $payment->update(['ifrs_receipt_id' => $journalEntry->id]);

            Log::info("Payment posted to IFRS", [
                'payment_id' => $payment->id,
                'ifrs_receipt_id' => $journalEntry->id,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to post payment to IFRS', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Auto-create cash receipts for all unmatched Wise credits
     * 
     * @param int|null $clientId Optional client ID to filter by
     * @return array Results with created payments and errors
     */
    public function autoCreateCashReceipts(?int $clientId = null): array
    {
        $query = BankTransaction::pending()
            ->fromSource(BankTransaction::SOURCE_WISE)
            ->where('type', BankTransaction::TYPE_CREDIT);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $transactions = $query->get();
        $created = [];
        $skipped = 0;
        $errors = [];

        foreach ($transactions as $transaction) {
            // Try to find a matching client by payer name or reference
            $matchedClientId = $this->findClientForTransaction($transaction, $clientId);

            if (!$matchedClientId) {
                $skipped++;
                $errors[] = [
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'error' => 'No matching client found',
                ];
                continue;
            }

            $payment = $this->createCashReceiptFromBankTransaction(
                $transaction,
                $matchedClientId,
                null,
                true
            );

            if ($payment) {
                $created[] = $payment;
            } else {
                $errors[] = [
                    'transaction_id' => $transaction->id,
                    'error' => 'Failed to create payment',
                ];
            }
        }

        return [
            'created' => $created,
            'count' => count($created),
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Find a client for a bank transaction based on payer name or reference
     * 
     * @param BankTransaction $transaction
     * @param int|null $preferredClientId
     * @return int|null Client ID or null
     */
    protected function findClientForTransaction(BankTransaction $transaction, ?int $preferredClientId = null): ?int
    {
        // If a preferred client ID is provided, verify it exists
        if ($preferredClientId) {
            $client = Client::find($preferredClientId);
            if ($client) {
                return $client->id;
            }
        }

        // Try to match by payer name
        if (!empty($transaction->payer_name)) {
            $client = Client::where('name', 'like', '%' . $transaction->payer_name . '%')->first();
            if ($client) {
                return $client->id;
            }
        }

        // Try to match by reference (often contains client name or invoice number)
        if (!empty($transaction->reference)) {
            // Try exact reference match with clients
            $client = Client::where('name', 'like', '%' . $transaction->reference . '%')->first();
            if ($client) {
                return $client->id;
            }
        }

        return null;
    }

    /**
     * Auto-create a purchase/expense from an unmatched Wise debit
     * 
     * @param BankTransaction $bankTransaction The unmatched debit transaction
     * @param int $supplierId The supplier to associate with the expense
     * @param string $category The expense category
     * @param int|null $paidByUserId The user recording the expense
     * @param bool $markAsPaid Whether to mark the expense as paid immediately
     * @return Expense|null The created expense or null on failure
     */
    public function createPurchaseFromBankTransaction(
        BankTransaction $bankTransaction,
        int $supplierId,
        string $category = 'other',
        ?int $paidByUserId = null,
        bool $markAsPaid = true
    ): ?Expense {
        // Validate this is a debit transaction
        if ($bankTransaction->type !== BankTransaction::TYPE_DEBIT) {
            Log::warning("Cannot create purchase from credit transaction", [
                'bank_transaction_id' => $bankTransaction->id,
                'type' => $bankTransaction->type,
            ]);
            return null;
        }

        // Validate supplier exists
        $supplier = Client::find($supplierId);
        if (!$supplier) {
            Log::error("Supplier not found for purchase creation", [
                'supplier_id' => $supplierId,
                'bank_transaction_id' => $bankTransaction->id,
            ]);
            return null;
        }

        // Validate category
        $validCategories = Expense::CATEGORIES;
        if (!in_array($category, $validCategories)) {
            $category = 'other';
        }

        try {
            // Create the expense
            $expense = Expense::create([
                'supplier_id' => $supplierId,
                'category' => $category,
                'amount' => $bankTransaction->amount,
                'tax_amount' => 0, // GST not included in bank amount for simplicity
                'total' => $bankTransaction->amount,
                'expense_date' => $bankTransaction->transaction_date,
                'due_date' => $bankTransaction->transaction_date,
                'status' => Expense::STATUS_DRAFT,
                'description' => "Auto-created from Wise transaction {$bankTransaction->source_id}. Description: {$bankTransaction->description}",
                'reference' => $bankTransaction->reference,
                'notes' => "Imported from Wise - {$bankTransaction->merchant_name}",
                'paid_by_user_id' => $paidByUserId,
                'paid_date' => $markAsPaid ? $bankTransaction->transaction_date : null,
                'payment_method' => Payment::METHOD_BANK_TRANSFER,
            ]);

            // Mark bank transaction as matched
            $bankTransaction->markAsMatched($expense->id, 'expense');

            Log::info("Purchase/Expense created from unmatched Wise debit", [
                'expense_id' => $expense->id,
                'supplier_id' => $supplierId,
                'amount' => $expense->total,
                'bank_transaction_id' => $bankTransaction->id,
            ]);

            // Mark as paid if requested (creates IFRS journal entry)
            if ($markAsPaid) {
                $expense->markAsPaid(Payment::METHOD_BANK_TRANSFER, $paidByUserId);
            }

            return $expense;

        } catch (\Exception $e) {
            Log::error("Failed to create purchase from Wise transaction", [
                'bank_transaction_id' => $bankTransaction->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Auto-create purchases/expenses for all unmatched Wise debits
     * 
     * @param string|null $category Default category to use
     * @param bool $markAsPaid Whether to mark expenses as paid
     * @return array Results with created expenses and errors
     */
    public function autoCreatePurchases(string $category = 'other', bool $markAsPaid = true): array
    {
        $transactions = BankTransaction::pending()
            ->fromSource(BankTransaction::SOURCE_WISE)
            ->where('type', BankTransaction::TYPE_DEBIT)
            ->get();

        $created = [];
        $skipped = 0;
        $errors = [];

        foreach ($transactions as $transaction) {
            // Try to find a matching supplier by merchant name
            $matchedSupplierId = $this->findSupplierForTransaction($transaction);

            if (!$matchedSupplierId) {
                $skipped++;
                $errors[] = [
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'error' => 'No matching supplier found',
                ];
                continue;
            }

            $expense = $this->createPurchaseFromBankTransaction(
                $transaction,
                $matchedSupplierId,
                $category,
                null,
                $markAsPaid
            );

            if ($expense) {
                $created[] = $expense;
            } else {
                $errors[] = [
                    'transaction_id' => $transaction->id,
                    'error' => 'Failed to create expense',
                ];
            }
        }

        return [
            'created' => $created,
            'count' => count($created),
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Find a supplier for a bank transaction based on merchant name
     * 
     * @param BankTransaction $transaction
     * @return int|null Supplier ID or null
     */
    protected function findSupplierForTransaction(BankTransaction $transaction): ?int
    {
        // Try to match by merchant name
        if (!empty($transaction->merchant_name)) {
            $supplier = Client::where('name', 'like', '%' . $transaction->merchant_name . '%')->first();
            if ($supplier) {
                return $supplier->id;
            }
        }

        // Try to match by payee name
        if (!empty($transaction->payee_name)) {
            $supplier = Client::where('name', 'like', '%' . $transaction->payee_name . '%')->first();
            if ($supplier) {
                return $supplier->id;
            }
        }

        // Try to match by reference
        if (!empty($transaction->reference)) {
            $supplier = Client::where('name', 'like', '%' . $transaction->reference . '%')->first();
            if ($supplier) {
                return $supplier->id;
            }
        }

        return null;
    }

    /**
     * Get expense category suggestions based on merchant name
     * 
     * @param string $merchantName
     * @return string Suggested category
     */
    public function suggestExpenseCategory(string $merchantName): string
    {
        $merchantLower = strtolower($merchantName);
        
        $categoryMap = [
            'aws' => 'software',
            'google' => 'software',
            'microsoft' => 'software',
            'slack' => 'software',
            'zoom' => 'software',
            'xero' => 'software',
            'myob' => 'software',
            'airline' => 'travel',
            'hotel' => 'travel',
            'uber' => 'travel',
            'didi' => 'travel',
            'restaurant' => 'meals',
            'cafe' => 'meals',
            'office' => 'office_supplies',
            'staples' => 'office_supplies',
        ];

        foreach ($categoryMap as $keyword => $category) {
            if (str_contains($merchantLower, $keyword)) {
                return $category;
            }
        }

        return 'other';
    }
}
