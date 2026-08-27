<?php

namespace App\Console\Commands;

use App\Models\Prepayment;
use App\Services\PrepaymentService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Post the monthly amortisation entries for prepaid service contracts
 * (subscriptions, licences, prepaid domain renewals, finite-life
 * intangibles). Rows whose periods are fully posted are skipped, so
 * reruns only process whatever is still missing — safe to run daily,
 * and a daily catch-up loop recovers missed runs automatically.
 */
class AmortisePrepayments extends Command
{
    protected $signature = 'prepayments:amortise
                            {--prepayment-id= : Amortise only this prepayment}
                            {--as-of= : Run for this date instead of today (backfill)}
                            {--dry-run : Report what would be posted without posting}';

    protected $description = 'Post due monthly amortisation entries for prepaid subscriptions and licences';

    public function handle(): int
    {
        $asOf = $this->option('as-of') ? Carbon::parse($this->option('as-of')) : null;
        $dryRun = (bool) $this->option('dry-run');

        $query = Prepayment::active()
            ->whereDate('next_period_date', '<=', ($asOf ?? today())->endOfDay()->toDateString())
            ->orderBy('id');

        if ($this->option('prepayment-id')) {
            $query->where('id', (int) $this->option('prepayment-id'));
        }

        $prepayments = $query->get();
        if ($prepayments->isEmpty()) {
            $this->info('No prepayments are due for amortisation.');
            return Command::SUCCESS;
        }

        $posted = 0;
        $failed = 0;

        foreach ($prepayments as $prepayment) {
            $label = "#{$prepayment->id} {$prepayment->description}";

            try {
                $count = PrepaymentService::amortise($prepayment, $asOf, dryRun: $dryRun);
                $posted += $count;

                if ($dryRun) {
                    $this->line("WOULD POST {$count} month(s) — {$label}");
                } elseif ($count > 0) {
                    $this->info("POSTED {$count} month(s) — {$label}");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("FAILED {$label} — {$e->getMessage()}");
                \Illuminate\Support\Facades\Log::error('Prepayment amortisation failed', [
                    'prepayment_id' => $prepayment->id,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                // Continue: a closed reporting period (or similar) on one
                // prepayment must not block the others; the cursor only
                // advances on success so this retries next run.
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d month(s) across %d prepayment(s)%s',
            $dryRun ? 'Dry run complete' : 'Amortisation complete',
            $posted,
            $prepayments->count(),
            $failed ? ", {$failed} FAILED" : ''
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
