<?php

namespace App\Console\Commands;

use App\Models\DividendDeclaration;
use App\Services\DividendService;
use Illuminate\Console\Command;

/**
 * Retry/fallback sender for dividend statement emails. Statements normally
 * go out when a payment run is recorded (DividendDeclarationController);
 * this command sweeps completed declarations with unsent statements —
 * catching failures (bad mailbox, transient SMTP error) and shareholders
 * whose email was added after the run.
 */
class SendDividendStatements extends Command
{
    protected $signature = 'dividends:send-statements
                            {--declaration= : Specific declaration ID to resend}
                            {--force : Resend statements already marked sent}
                            {--dry-run : Show what would be sent without sending}';

    protected $description = 'Send dividend statement emails for completed declarations';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $declarations = $this->option('declaration')
            ? DividendDeclaration::whereKey((int) $this->option('declaration'))->get()
            : DividendDeclaration::query()
                ->where('status', DividendDeclaration::STATUS_COMPLETED)
                ->whereHas('distributions', fn ($q) => $q->where('status', 'paid')->where('statement_sent', false))
                ->orderBy('payment_date')
                ->get();

        if ($declarations->isEmpty()) {
            $this->info('No declarations with unsent statements.');

            return Command::SUCCESS;
        }

        $totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'missing_email' => 0];

        foreach ($declarations as $declaration) {
            $results = DividendService::sendStatements($declaration, force: $force, dryRun: $dryRun);

            foreach ($totals as $key => $_) {
                $totals[$key] += $results[$key];
            }

            $this->line(sprintf(
                '%s%s: %d sent, %d failed, %d without email%s',
                $dryRun ? '[DRY RUN] ' : '',
                $declaration->declaration_number,
                $results['sent'],
                $results['failed'],
                $results['missing_email'],
                $results['failed'] ? ' — check the log for errors' : '',
            ));
        }

        $this->info(sprintf(
            'Done. Sent: %d, Failed: %d, No email: %d.',
            $totals['sent'],
            $totals['failed'],
            $totals['missing_email'],
        ));

        return $totals['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
