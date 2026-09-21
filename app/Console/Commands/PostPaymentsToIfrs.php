<?php

namespace App\Console\Commands;

use App\Models\BillPayment;
use App\Models\Payment;
use App\Models\ReimbursementPayment;
use Illuminate\Console\Command;

/**
 * Backfill/retry IFRS posting for payments that were never posted. Posting
 * from the UI is best-effort (logged, non-fatal), so swallowed failures —
 * e.g. before IFRSSeeder ran, or for a fiscal year with no period row —
 * stay unposted forever unless this command re-attempts them.
 *
 * Idempotent: rows whose ifrs id is already set are skipped, so reruns
 * only process whatever is still missing.
 */
class PostPaymentsToIfrs extends Command
{
    protected $signature = 'ifrs:post-payments
                            {--payment-id= : Only process the client payment with this id}
                            {--dry-run : Show what would be posted without making changes}';

    protected $description = 'Post unposted client and supplier payments to the IFRS ledger';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $paymentId = $this->option('payment-id');

        $payments = Payment::whereNull('ifrs_receipt_id')
            ->where('status', '!=', Payment::STATUS_VOID)
            ->when($paymentId, fn ($query) => $query->where('id', (int) $paymentId))
            ->orderBy('id')
            ->get();

        // Pending = employee expense awaiting approval, not a posting
        // failure — the approve action posts those.
        $billPayments = BillPayment::whereNull('ifrs_payment_id')
            ->where('status', BillPayment::STATUS_COMPLETED)
            ->orderBy('id')
            ->get();

        $reimbursements = ReimbursementPayment::whereNull('ifrs_transaction_id')
            ->where('status', ReimbursementPayment::STATUS_COMPLETED)
            ->orderBy('id')
            ->get();

        if ($payments->isEmpty() && $billPayments->isEmpty() && $reimbursements->isEmpty()) {
            $this->info('No unposted payments found.');

            return Command::SUCCESS;
        }

        $posted = 0;
        $failed = 0;

        foreach ($payments as $payment) {
            $label = sprintf(
                'payment %s (%s, $%s)',
                $payment->payment_number,
                $payment->payment_date->format('d/m/Y'),
                number_format($payment->amount, 2),
            );

            if ($dryRun) {
                $this->warn("  Would post {$label}");

                continue;
            }

            if ($payment->postToIFRS()) {
                $this->info("  Posted {$label}");
                $posted++;
            } else {
                $this->error("  FAILED  {$label} — {$payment->lastPostingError}");
                $failed++;
            }
        }

        foreach ($billPayments as $billPayment) {
            $label = sprintf(
                'bill payment %s (%s, $%s)',
                $billPayment->payment_number,
                $billPayment->payment_date->format('d/m/Y'),
                number_format($billPayment->amount, 2),
            );

            if ($dryRun) {
                $this->warn("  Would post {$label}");

                continue;
            }

            if ($billPayment->postToIFRS()) {
                $this->info("  Posted {$label}");
                $posted++;
            } else {
                $this->error("  FAILED  {$label} — {$billPayment->lastPostingError}");
                $failed++;
            }
        }

        foreach ($reimbursements as $reimbursement) {
            $label = sprintf(
                'reimbursement %s (%s, $%s)',
                $reimbursement->payment_number,
                $reimbursement->payment_date->format('d/m/Y'),
                number_format($reimbursement->amount, 2),
            );

            if ($dryRun) {
                $this->warn("  Would post {$label}");

                continue;
            }

            if ($reimbursement->postToIFRS()) {
                $this->info("  Posted {$label}");
                $posted++;
            } else {
                $this->error("  FAILED  {$label} — {$reimbursement->lastPostingError}");
                $failed++;
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->warn(sprintf(
                'Dry run — no changes made. %d payment(s) would be posted.',
                $payments->count() + $billPayments->count() + $reimbursements->count(),
            ));
        } else {
            $this->info('Summary:');
            $this->info("  Posted: {$posted}");
            $this->info("  Failed: {$failed}");
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
