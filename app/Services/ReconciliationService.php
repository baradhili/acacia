<?php

namespace App\Services;

use App\Models\WiseTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use IFRS\Models\Ledger;

class ReconciliationService
{
    // Matching tolerances
    private const AMOUNT_TOLERANCE = 0.01; // $0.01 tolerance for amount matching
    private const DATE_TOLERANCE_DAYS = 3; // 3 days tolerance for date matching

    /**
     * Attempt to auto-match a Wise transaction against IFRS ledgers
     */
    public function matchTransaction(WiseTransaction $wiseTransaction): ?int
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
    private function findMatchingLedger(WiseTransaction $wiseTransaction): ?Ledger
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
        if ($wiseTransaction->type === WiseTransaction::TYPE_CREDIT) {
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

        $pendingTransactions = WiseTransaction::pending()->get();

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
    public function manualMatch(WiseTransaction $wiseTransaction, int $ledgerId, string $type = 'ledger'): bool
    {
        $wiseTransaction->markAsMatched($ledgerId, $type);
        return true;
    }

    /**
     * Get matching candidates for a Wise transaction
     */
    public function getMatchingCandidates(WiseTransaction $wiseTransaction): Collection
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
    public function calculateMatchScore(WiseTransaction $wiseTransaction, Ledger $ledger): float
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
}
