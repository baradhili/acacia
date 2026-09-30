<?php

namespace App\Console\Commands;

use App\Mail\ClientStatementMail;
use App\Models\Client;
use App\Services\ClientStatementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails monthly ClientStatementMail statements (1st of the month,
 * 09:00, client-statements.log): all clients with an outstanding
 * balance via ClientStatementService, or one --client; --month
 * picks the period (default the current month end). Clients without
 * an email are skipped and per-client send failures are logged and
 * counted, never fatal — which is currently every send: the
 * mailable renders emails.client-statement, a view that does not
 * exist (todo-list), so a run exits SUCCESS with all clients
 * counted as skipped and the failures only in the log. --dry-run
 * prints the balance summary instead of sending (dry-runs are
 * unaffected by the missing view). No sent-record is kept — a
 * manual re-run re-emails.
 */
class SendClientStatements extends Command
{
    protected $signature = 'statements:send
                            {--month= : Month for statement (YYYY-MM format)}
                            {--client= : Specific client ID to send statement to}
                            {--dry-run : Show what would be done without sending}';

    protected $description = 'Generate and send monthly client statements';

    public function handle(): int
    {
        $month = $this->option('month');
        $clientId = $this->option('client');
        $dryRun = $this->option('dry-run');

        $service = new ClientStatementService;

        // Determine period end date
        $periodEnd = $month
            ? Carbon::createFromFormat('Y-m', $month)->endOfMonth()
            : Carbon::now()->endOfMonth();

        $this->info("Generating statements for {$periodEnd->format('F Y')}...");

        if ($clientId) {
            // Send to specific client
            $client = Client::find($clientId);
            if (! $client) {
                $this->error("Client not found: {$clientId}");

                return Command::FAILURE;
            }

            $statement = $service->generateStatement($client, $periodEnd);
            $this->sendStatement($client, $statement, $dryRun);
        } else {
            // Send to all clients with outstanding balances
            $statements = $service->generateStatementsForAllClients($periodEnd);

            $sent = 0;
            $skipped = 0;

            foreach ($statements as $statement) {
                $client = $statement['client'];

                if (! $client->email) {
                    $this->warn("Skipping {$client->name} - no email address");
                    $skipped++;

                    continue;
                }

                if ($this->sendStatement($client, $statement, $dryRun)) {
                    $sent++;
                } else {
                    $skipped++;
                }
            }

            $this->info("Done. Sent: {$sent}, Skipped: {$skipped}");
        }

        return Command::SUCCESS;
    }

    protected function sendStatement($client, array $statement, bool $dryRun): bool
    {
        if ($dryRun) {
            $this->warn("Would send statement to {$client->email} for {$statement['period_label']}");
            $this->table(
                ['Opening Balance', 'Invoiced', 'Paid', 'Closing Balance'],
                [[
                    number_format($statement['opening_balance'], 2),
                    number_format($statement['total_invoiced'], 2),
                    number_format($statement['total_paid'], 2),
                    number_format($statement['closing_balance'], 2),
                ]]
            );

            return true;
        }

        try {
            Mail::to($client->email)->send(new ClientStatementMail($client, $statement));

            Log::info('Client statement sent', [
                'client_id' => $client->id,
                'client_email' => $client->email,
                'period' => $statement['period_label'],
                'closing_balance' => $statement['closing_balance'],
            ]);

            $this->info("Sent statement to {$client->email} (Balance: {$statement['closing_balance']})");

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send client statement', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            $this->error("Failed to send to {$client->email}: {$e->getMessage()}");

            return false;
        }
    }
}
