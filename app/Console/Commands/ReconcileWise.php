<?php

namespace App\Console\Commands;

use App\Models\WiseTransaction;
use App\Services\WiseService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ReconcileWise extends Command
{
    protected $signature = 'reconcile:wise 
                            {--days=30 : Number of days to fetch}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Fetch transactions from Wise API and reconcile with IFRS ledger';

    public function __construct(
        private WiseService $wiseService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $days = (int) $this->option('days');

        $this->info('Starting Wise reconciliation...');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Fetch transactions from Wise API
        $fromDate = Carbon::now()->subDays($days)->startOfDay();
        $toDate = Carbon::now()->endOfDay();

        $this->info("Fetching transactions from {$fromDate->toDateString()} to {$toDate->toDateString()}");

        $transactions = $this->wiseService->fetchTransactions($fromDate, $toDate);

        if ($transactions->isEmpty()) {
            $this->info('No new transactions found from Wise API.');
            return Command::SUCCESS;
        }

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
