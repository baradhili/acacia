<?php

namespace App\Console\Commands;

use App\Models\WiseTransaction;
use App\Services\ReconciliationService;
use App\Services\WiseService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ReconcileWise extends Command
{
    protected $signature = 'reconcile:wise 
                            {--days=30 : Number of days to fetch}
                            {--dry-run : Show what would be done without making changes}
                            {--auto-match : Automatically match pending transactions}';

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
                $wiseId = $wiseTxn['id'] ?? null;
                if (!$wiseId) continue;

                // Check if already exists
                if (WiseTransaction::where('wise_id', $wiseId)->exists()) {
                    continue;
                }

                if (!$dryRun) {
                    WiseTransaction::create([
                        'wise_id' => $wiseId,
                        'reference' => $wiseTxn['reference'] ?? '',
                        'amount' => abs($wiseTxn['amount'] ?? 0),
                        'currency' => $wiseTxn['currency'] ?? 'AUD',
                        'type' => ($wiseTxn['type'] ?? 'DEBIT') === 'CREDIT' ? 'CREDIT' : 'DEBIT',
                        'transaction_date' => Carbon::parse($wiseTxn['date'] ?? now()),
                        'created_at_wise' => Carbon::parse($wiseTxn['created'] ?? now()),
                        'merchant_name' => $wiseTxn['merchantName'] ?? null,
                        'status' => WiseTransaction::STATUS_PENDING,
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
