<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReconciliationHistory;
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

    /**
     * Log a reconciliation action to history
     */
    protected function logHistory(
        BankTransaction $bankTransaction,
        string $action,
        string $status,
        ?int $linkedTransactionId = null,
        ?string $linkedTransactionType = null,
        ?string $details = null,
        ?string $notes = null,
        ?array $metadata = null
    ): ReconciliationHistory {
        return ReconciliationHistory::create([
            'bank_transaction_id' => $bankTransaction->id,
            'action' => $action,
            'status' => $status,
            'linked_transaction_id' => $linkedTransactionId,
            'linked_transaction_type' => $linkedTransactionType,
            'details' => $details,
            'notes' => $notes,
            'user_id' => auth()->id(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get reconciliation history for a transaction
     */
    public function getHistory(BankTransaction $bankTransaction): Collection
    {
        return ReconciliationHistory::forTransaction($bankTransaction->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get reconciliation history with statistics
     */
    public function getHistoryStats(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = ReconciliationHistory::query();

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        $total = $query->count();
        $successful = (clone $query)->where('status', ReconciliationHistory::STATUS_SUCCESS)->count();
        $failed = (clone $query)->where('status', ReconciliationHistory::STATUS_FAILED)->count();

        $byAction = (clone $query)
            ->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->pluck('count', 'action')
            ->toArray();

        $byUser = (clone $query)
            ->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) as count')
            ->groupBy('user_id')
            ->with('user:id,name,email')
            ->get()
            ->mapWithKeys(fn($item) => [$item->user?->name ?? 'Unknown' => $item->count])
            ->toArray();

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'by_action' => $byAction,
            'by_user' => $byUser,
        ];
    }

    /**
     * Attempt to auto-match a Wise transaction against IFRS ledgers
     */
    public function matchTransaction(BankTransaction $wiseTransaction): ?int
    {
        $matchedLedger = $this->findMatchingLedger($wiseTransaction);

        if ($matchedLedger) {
            $wiseTransaction->markAsMatched($matchedLedger->id, 'ledger');
            
            $this->logHistory(
                $wiseTransaction,
                ReconciliationHistory::ACTION_AUTO_MATCH,
                ReconciliationHistory::STATUS_SUCCESS,
                $matchedLedger->id,
                'ledger',
                "Auto-matched to ledger entry #{$matchedLedger->id} ({$matchedLedger->reference})"
            );
            
            return $matchedLedger->id;
        }

        $this->logHistory(
            $wiseTransaction,
            ReconciliationHistory::ACTION_AUTO_MATCH,
            ReconciliationHistory::STATUS_FAILED,
            null,
            null,
            'No matching ledger entry found'
        );

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

            // Log to history
            $this->logHistory(
                $bankTransaction,
                ReconciliationHistory::ACTION_AUTO_CREATE_RECEIPT,
                ReconciliationHistory::STATUS_SUCCESS,
                $payment->id,
                'payment',
                "Auto-created cash receipt #{$payment->payment_number} for client #{$clientId}",
                null,
                ['amount' => $payment->amount, 'client_id' => $clientId]
            );

            return $payment;

        } catch (\Exception $e) {
            Log::error("Failed to create cash receipt from Wise transaction", [
                'bank_transaction_id' => $bankTransaction->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->logHistory(
                $bankTransaction,
                ReconciliationHistory::ACTION_AUTO_CREATE_RECEIPT,
                ReconciliationHistory::STATUS_FAILED,
                null,
                null,
                $e->getMessage()
            );
            
            return null;
        }
    }

    /**
     * Post a payment to IFRS. Delegates to Payment::postToIFRS() so the
     * ledger-entry logic (double-entry, GST, entity resolution) lives in one
     * place and the two implementations can't drift out of sync.
     *
     * @param Payment $payment
     * @return bool Success status
     */
    protected function postPaymentToIFRS(Payment $payment): bool
    {
        return $payment->postToIFRS() !== null;
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

    /**
     * Ignore a bank transaction (mark as non-business)
     * 
     * @param BankTransaction $bankTransaction The transaction to ignore
     * @param string|null $reason Reason for ignoring
     * @return bool Success status
     */
    public function ignoreTransaction(BankTransaction $bankTransaction, ?string $reason = null): bool
    {
        // Cannot ignore already matched transactions
        if ($bankTransaction->status === BankTransaction::STATUS_MATCHED) {
            Log::warning("Cannot ignore matched bank transaction", [
                'bank_transaction_id' => $bankTransaction->id,
            ]);
            return false;
        }

        // Cannot ignore already ignored transactions
        if ($bankTransaction->status === BankTransaction::STATUS_IGNORED) {
            Log::warning("Bank transaction is already ignored", [
                'bank_transaction_id' => $bankTransaction->id,
            ]);
            return false;
        }

        try {
            $ignoreReason = $reason ?? 'Marked as non-business transaction';
            $ignoreReason .= " on " . now()->toDateTimeString();

            $bankTransaction->markAsIgnored($ignoreReason);

            Log::info("Bank transaction ignored", [
                'bank_transaction_id' => $bankTransaction->id,
                'reason' => $ignoreReason,
                'ignored_by' => auth()->id() ?? 'system',
            ]);

            // Log to history
            $this->logHistory(
                $bankTransaction,
                ReconciliationHistory::ACTION_IGNORE,
                ReconciliationHistory::STATUS_SUCCESS,
                null,
                null,
                $ignoreReason
            );

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to ignore bank transaction", [
                'bank_transaction_id' => $bankTransaction->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->logHistory(
                $bankTransaction,
                ReconciliationHistory::ACTION_IGNORE,
                ReconciliationHistory::STATUS_FAILED,
                null,
                null,
                $e->getMessage()
            );
            
            return false;
        }
    }

    /**
     * Ignore multiple bank transactions in batch
     * 
     * @param array $transactionIds Array of transaction IDs to ignore
     * @param string|null $reason Reason for ignoring
     * @return array Results with counts
     */
    public function ignoreTransactions(array $transactionIds, ?string $reason = null): array
    {
        $ignored = 0;
        $skipped = 0;
        $errors = [];

        foreach ($transactionIds as $id) {
            $bankTxn = BankTransaction::find($id);
            
            if (!$bankTxn) {
                $errors[] = ['id' => $id, 'error' => 'Transaction not found'];
                continue;
            }

            if ($this->ignoreTransaction($bankTxn, $reason)) {
                $ignored++;
            } else {
                $skipped++;
            }
        }

        return [
            'ignored' => $ignored,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Restore an ignored transaction back to pending
     * 
     * @param BankTransaction $bankTransaction The ignored transaction to restore
     * @return bool Success status
     */
    public function restoreIgnoredTransaction(BankTransaction $bankTransaction): bool
    {
        if ($bankTransaction->status !== BankTransaction::STATUS_IGNORED) {
            Log::warning("Bank transaction is not ignored", [
                'bank_transaction_id' => $bankTransaction->id,
                'current_status' => $bankTransaction->status,
            ]);
            return false;
        }

        try {
            $bankTransaction->update([
                'status' => BankTransaction::STATUS_PENDING,
                'notes' => $bankTransaction->notes 
                    ? $bankTransaction->notes . "\n" . "Restored from ignored on " . now()->toDateTimeString()
                    : "Restored from ignored on " . now()->toDateTimeString(),
            ]);

            Log::info("Ignored bank transaction restored to pending", [
                'bank_transaction_id' => $bankTransaction->id,
                'restored_by' => auth()->id() ?? 'system',
            ]);

            // Log to history
            $this->logHistory(
                $bankTransaction,
                ReconciliationHistory::ACTION_UNIGNORE,
                ReconciliationHistory::STATUS_SUCCESS,
                null,
                null,
                'Restored from ignored status to pending'
            );

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to restore ignored bank transaction", [
                'bank_transaction_id' => $bankTransaction->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->logHistory(
                $bankTransaction,
                ReconciliationHistory::ACTION_UNIGNORE,
                ReconciliationHistory::STATUS_FAILED,
                null,
                null,
                $e->getMessage()
            );
            
            return false;
        }
    }

    /**
     * Manually link a bank transaction to an existing IFRS transaction
     * 
     * @param BankTransaction $bankTransaction The bank transaction to link
     * @param string $transactionType The type of IFRS transaction (invoice, payment, expense, ledger)
     * @param int $transactionId The ID of the IFRS transaction
     * @param string|null $notes Optional notes explaining the manual link
     * @return bool Success status
     */
    public function manualOverrideLink(
        BankTransaction $bankTransaction,
        string $transactionType,
        int $transactionId,
        ?string $notes = null
    ): bool {
        // Validate transaction type
        $validTypes = ['invoice', 'payment', 'expense', 'ledger'];
        if (!in_array($transactionType, $validTypes)) {
            Log::warning("Invalid transaction type for manual override", [
                'transaction_type' => $transactionType,
                'bank_transaction_id' => $bankTransaction->id,
            ]);
            return false;
        }

        // Validate that the transaction exists based on type
        $transaction = $this->findTransaction($transactionType, $transactionId);
        if (!$transaction) {
            Log::error("Transaction not found for manual override", [
                'transaction_type' => $transactionType,
                'transaction_id' => $transactionId,
            ]);
            return false;
        }

        // Validate transaction is not already matched
        if ($bankTransaction->status === BankTransaction::STATUS_MATCHED) {
            Log::warning("Bank transaction already matched, cannot manually override", [
                'bank_transaction_id' => $bankTransaction->id,
            ]);
            return false;
        }

        try {
            // Build notes with explanation
            $linkNotes = $notes ?? "Manually linked to {$transactionType} #{$transactionId}";
            $linkNotes .= " on " . now()->toDateTimeString();

            // Mark the bank transaction as matched with manual override
            $bankTransaction->update([
                'status' => BankTransaction::STATUS_MATCHED,
                'matched_transaction_id' => $transactionId,
                'matched_transaction_type' => $transactionType,
                'matched_at' => now(),
                'notes' => $bankTransaction->notes 
                    ? $bankTransaction->notes . "\n" . $linkNotes
                    : $linkNotes,
            ]);

            Log::info("Bank transaction manually linked to IFRS transaction", [
                'bank_transaction_id' => $bankTransaction->id,
                'transaction_type' => $transactionType,
                'transaction_id' => $transactionId,
                'linked_by' => auth()->id() ?? 'system',
            ]);

            // Log to history
            $this->logHistory(
                $bankTransaction,
                ReconciliationHistory::ACTION_MANUAL_MATCH,
                ReconciliationHistory::STATUS_SUCCESS,
                $transactionId,
                $transactionType,
                $linkNotes
            );

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to manually link bank transaction", [
                'bank_transaction_id' => $bankTransaction->id,
                'transaction_type' => $transactionType,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            
            $this->logHistory(
                $bankTransaction,
                ReconciliationHistory::ACTION_MANUAL_MATCH,
                ReconciliationHistory::STATUS_FAILED,
                $transactionId,
                $transactionType,
                $e->getMessage()
            );
            
            return false;
        }
    }

    /**
     * Find an IFRS transaction by type and ID
     * 
     * @param string $type Transaction type (invoice, payment, expense, ledger)
     * @param int $id Transaction ID
     * @return mixed|null The transaction or null
     */
    protected function findTransaction(string $type, int $id): mixed
    {
        return match ($type) {
            'invoice' => Invoice::find($id),
            'payment' => Payment::find($id),
            'expense' => Expense::find($id),
            'ledger' => Ledger::find($id),
            default => null,
        };
    }

    /**
     * Get available IFRS transactions for manual linking
     * 
     * @param BankTransaction $bankTransaction The bank transaction to match
     * @param string $type Filter by transaction type (optional)
     * @param int $limit Limit results
     * @return Collection Available transactions
     */
    public function getAvailableTransactionsForLinking(
        BankTransaction $bankTransaction,
        ?string $type = null,
        int $limit = 50
    ): Collection {
        $amount = $bankTransaction->amount;
        $dateFrom = $bankTransaction->transaction_date->copy()->subDays(self::DATE_TOLERANCE_DAYS);
        $dateTo = $bankTransaction->transaction_date->copy()->addDays(self::DATE_TOLERANCE_DAYS);

        $results = collect();

        // Search invoices if type is null or 'invoice'
        if ($type === null || $type === 'invoice') {
            $invoices = Invoice::whereBetween('total', [
                    $amount - self::AMOUNT_TOLERANCE,
                    $amount + self::AMOUNT_TOLERANCE,
                ])
                ->whereBetween('invoice_date', [$dateFrom, $dateTo])
                ->whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_VIEWED, Invoice::STATUS_PARTIALLY_PAID])
                ->limit($limit)
                ->get()
                ->map(function ($invoice) {
                    return [
                        'type' => 'invoice',
                        'id' => $invoice->id,
                        'reference' => $invoice->invoice_number,
                        'amount' => $invoice->total,
                        'date' => $invoice->invoice_date,
                        'client' => $invoice->client?->name ?? 'Unknown',
                        'status' => $invoice->status,
                    ];
                });
            $results = $results->merge($invoices);
        }

        // Search payments if type is null or 'payment'
        if ($type === null || $type === 'payment') {
            $payments = Payment::whereBetween('amount', [
                    $amount - self::AMOUNT_TOLERANCE,
                    $amount + self::AMOUNT_TOLERANCE,
                ])
                ->whereBetween('payment_date', [$dateFrom, $dateTo])
                ->where('status', Payment::STATUS_COMPLETED)
                ->limit($limit)
                ->get()
                ->map(function ($payment) {
                    return [
                        'type' => 'payment',
                        'id' => $payment->id,
                        'reference' => $payment->payment_number,
                        'amount' => $payment->amount,
                        'date' => $payment->payment_date,
                        'client' => $payment->client?->name ?? 'Unknown',
                        'status' => $payment->status,
                    ];
                });
            $results = $results->merge($payments);
        }

        // Search expenses if type is null or 'expense'
        if ($type === null || $type === 'expense') {
            $expenses = Expense::whereBetween('total', [
                    $amount - self::AMOUNT_TOLERANCE,
                    $amount + self::AMOUNT_TOLERANCE,
                ])
                ->whereBetween('expense_date', [$dateFrom, $dateTo])
                ->whereIn('status', [Expense::STATUS_APPROVED, Expense::STATUS_PAID])
                ->limit($limit)
                ->get()
                ->map(function ($expense) {
                    return [
                        'type' => 'expense',
                        'id' => $expense->id,
                        'reference' => $expense->reference ?? "EXP-{$expense->id}",
                        'amount' => $expense->total,
                        'date' => $expense->expense_date,
                        'supplier' => $expense->supplier?->name ?? 'Unknown',
                        'category' => $expense->category,
                        'status' => $expense->status,
                    ];
                });
            $results = $results->merge($expenses);
        }

        // Search ledger entries if type is null or 'ledger'
        if ($type === null || $type === 'ledger') {
            $ledgers = Ledger::whereBetween('amount', [
                    $amount - self::AMOUNT_TOLERANCE,
                    $amount + self::AMOUNT_TOLERANCE,
                ])
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->with('account')
                ->limit($limit)
                ->get()
                ->map(function ($ledger) {
                    return [
                        'type' => 'ledger',
                        'id' => $ledger->id,
                        'reference' => $ledger->reference ?? "Ledger-{$ledger->id}",
                        'amount' => $ledger->amount,
                        'date' => $ledger->date,
                        'account' => $ledger->account?->name ?? 'Unknown',
                        'entry_type' => $ledger->entry_type,
                    ];
                });
            $results = $results->merge($ledgers);
        }

        return $results->take($limit);
    }

    /**
     * Unlink a previously matched bank transaction
     * 
     * @param BankTransaction $bankTransaction The bank transaction to unlink
     * @param string|null $reason Reason for unlinking
     * @return bool Success status
     */
    public function unlinkTransaction(BankTransaction $bankTransaction, ?string $reason = null): bool
    {
        if ($bankTransaction->status !== BankTransaction::STATUS_MATCHED) {
            return false;
        }

        try {
            $previousMatch = [
                'previous_transaction_id' => $bankTransaction->matched_transaction_id,
                'previous_transaction_type' => $bankTransaction->matched_transaction_type,
                'previous_matched_at' => $bankTransaction->matched_at,
            ];

            $bankTransaction->update([
                'status' => BankTransaction::STATUS_PENDING,
                'matched_transaction_id' => null,
                'matched_transaction_type' => null,
                'matched_at' => null,
                'notes' => $bankTransaction->notes 
                    ? $bankTransaction->notes . "\n" . "Unlinked on " . now()->toDateTimeString() . ". Reason: " . ($reason ?? 'No reason provided')
                    : "Unlinked on " . now()->toDateTimeString() . ". Reason: " . ($reason ?? 'No reason provided'),
            ]);

            Log::info("Bank transaction unlinked", [
                'bank_transaction_id' => $bankTransaction->id,
                'previous_match' => $previousMatch,
                'reason' => $reason,
            ]);

            // Log to history
            $this->logHistory(
                $bankTransaction,
                ReconciliationHistory::ACTION_UNMATCH,
                ReconciliationHistory::STATUS_SUCCESS,
                $previousMatch['previous_transaction_id'],
                $previousMatch['previous_transaction_type'],
                "Unlinked previous match. Reason: " . ($reason ?? 'No reason provided'),
                null,
                ['previous_match' => $previousMatch]
            );

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to unlink bank transaction", [
                'bank_transaction_id' => $bankTransaction->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->logHistory(
                $bankTransaction,
                ReconciliationHistory::ACTION_UNMATCH,
                ReconciliationHistory::STATUS_FAILED,
                null,
                null,
                $e->getMessage()
            );
            
            return false;
        }
    }
}
