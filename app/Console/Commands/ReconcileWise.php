<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use App\Services\ReconciliationService;
use App\Services\WiseService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ReconcileWise extends Command
{
    protected $signature = 'reconcile:wise
                            {--days=30 : Number of days to fetch}
                            {--dry-run : Show what would be done without making changes}
                            {--auto-match : Automatically match pending transactions}
                            {--auto-create-receipts : Auto-create cash receipts from unmatched Wise credits}
                            {--auto-create-purchases : Auto-create paid bills from unmatched Wise debits}';

    protected $description = 'Fetch transactions from Wise API and reconcile with IFRS ledger';

    public function __construct(
        private WiseService $wiseService,
        private ReconciliationService $reconciliationService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $days = (int) $this->option('days');
        $autoMatch = $this->option('auto-match');
        $autoCreateReceipts = $this->option('auto-create-receipts');
        $autoCreatePurchases = $this->option('auto-create-purchases');

        $this->info('Starting Wise reconciliation...');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Show tolerance settings
        $tolerances = $this->reconciliationService->getTolerances();
        $this->info("Matching tolerances: Amount ±\${$tolerances['amount_tolerance']}, Date ±{$tolerances['date_tolerance_days']} days");

        // Fetch transactions from Wise API
        $fromDate = Carbon::now()->subDays($days)->startOfDay();
        $toDate = Carbon::now()->endOfDay();

        $this->info("Fetching transactions from {$fromDate->toDateString()} to {$toDate->toDateString()}");

        $transactions = $this->wiseService->fetchTransactions($fromDate, $toDate);

        if ($transactions->isEmpty()) {
            $this->info('No new transactions found from Wise API.');
        } else {
            $this->info("Found {$transactions->count()} transactions");

            // Import transactions
            $imported = 0;
            foreach ($transactions as $wiseTxn) {
                $sourceId = $wiseTxn['id'] ?? null;
                if (!$sourceId) continue;

                // Check if already exists
                if (BankTransaction::where('source', BankTransaction::SOURCE_WISE)->where('source_id', $sourceId)->exists()) {
                    continue;
                }

                if (!$dryRun) {
                    BankTransaction::create([
                        'source' => BankTransaction::SOURCE_WISE,
                        'source_id' => $sourceId,
                        'reference' => $wiseTxn['reference'] ?? '',
                        'amount' => abs($wiseTxn['amount'] ?? 0),
                        'currency' => $wiseTxn['currency'] ?? 'AUD',
                        'type' => ($wiseTxn['type'] ?? 'DEBIT') === 'CREDIT' ? 'CREDIT' : 'DEBIT',
                        'transaction_date' => Carbon::parse($wiseTxn['date'] ?? now()),
                        'created_at_source' => Carbon::parse($wiseTxn['created'] ?? now()),
                        'merchant_name' => $wiseTxn['merchantName'] ?? null,
                        'payer_name' => $wiseTxn['payerName'] ?? null,
                        'status' => BankTransaction::STATUS_PENDING,
                    ]);
                }
                $imported++;
            }

            $this->info("Imported {$imported} new transactions");
        }

        // Auto-match pending transactions
        if ($autoMatch && !$dryRun) {
            $this->info('Running auto-match...');
            $matchResults = $this->reconciliationService->autoMatchAll();
            $this->info("Auto-matched: {$matchResults['matched']} transactions");
            if ($matchResults['unmatched'] > 0) {
                $this->warn("Unmatched: {$matchResults['unmatched']} transactions");
            }
        }

        // Auto-create cash receipts from unmatched credits
        if ($autoCreateReceipts && !$dryRun) {
            $this->info('Creating cash receipts from unmatched Wise credits...');
            $receiptResults = $this->reconciliationService->autoCreateCashReceipts();
            $this->info("Cash receipts created: {$receiptResults['count']}");
            if ($receiptResults['skipped'] > 0) {
                $this->warn("Skipped (no matching client): {$receiptResults['skipped']}");
            }
            if (!empty($receiptResults['errors'])) {
                foreach ($receiptResults['errors'] as $error) {
                    $this->error("Error: {$error['transaction_id']} - {$error['error']}");
                }
            }
        }

        // Auto-create paid bills from unmatched debits
        if ($autoCreatePurchases && !$dryRun) {
            $this->info('Creating bills from unmatched Wise debits...');
            $billResults = $this->reconciliationService->autoCreatePurchases(true);
            $this->info("Bills created: {$billResults['count']}");
            if ($billResults['skipped'] > 0) {
                $this->warn("Skipped (no matching supplier): {$billResults['skipped']}");
            }
            if (!empty($billResults['errors'])) {
                foreach ($billResults['errors'] as $error) {
                    $this->error("Error: {$error['transaction_id']} - {$error['error']}");
                }
            }
        }

        // Show statistics
        $stats = $this->wiseService->getStatistics();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Transactions', $stats['total']],
                ['Pending', $stats['pending']],
                ['Matched', $stats['matched']],
                ['Ignored', $stats['ignored']],
            ]
        );

        $this->info('Wise reconciliation complete.');

        return Command::SUCCESS;
    }
}
