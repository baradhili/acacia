<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ClientStatementService
{
    /**
     * Generate statement data for a client for a specific period
     */
    public function generateStatement(Client $client, ?Carbon $periodEnd = null): array
    {
        $periodEnd = $periodEnd ?? Carbon::now()->endOfMonth();
        $periodStart = $periodEnd->copy()->startOfMonth();
        $previousPeriodEnd = $periodStart->copy()->subDay();

        // Get opening balance (sum of all invoices before period start)
        $openingBalance = $this->getOpeningBalance($client, $periodStart);

        // Get invoices in period
        $invoices = $this->getInvoicesInPeriod($client, $periodStart, $periodEnd);

        // Get payments in period
        $payments = $this->getPaymentsInPeriod($client, $periodStart, $periodEnd);

        // Get current balance (as of period end)
        $closingBalance = $this->getClosingBalance($client, $periodEnd);

        // Build line items
        $lineItems = $this->buildLineItems($invoices, $payments);

        return [
            'client' => $client,
            'client_name' => $client->name,
            'client_email' => $client->email,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'period_label' => $periodEnd->format('F Y'),
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'total_invoiced' => $invoices->sum('total'),
            'total_paid' => $payments->sum('amount'),
            'line_items' => $lineItems,
            'outstanding_amount' => $closingBalance,
            'generated_at' => now(),
        ];
    }

    /**
     * Get opening balance for client before a date
     */
    protected function getOpeningBalance(Client $client, Carbon $beforeDate): float
    {
        $invoicesBefore = Invoice::where('client_id', $client->id)
            ->where('issue_date', '<', $beforeDate)
            ->sum('total');

        $paymentsBefore = Payment::where('client_id', $client->id)
            ->where('payment_date', '<', $beforeDate)
            ->where('status', Payment::STATUS_COMPLETED)
            ->sum('amount');

        return $invoicesBefore - $paymentsBefore;
    }

    /**
     * Get invoices in period
     */
    protected function getInvoicesInPeriod(Client $client, Carbon $start, Carbon $end): Collection
    {
        return Invoice::where('client_id', $client->id)
            ->whereBetween('issue_date', [$start, $end])
            ->orderBy('issue_date')
            ->get();
    }

    /**
     * Get payments in period
     */
    protected function getPaymentsInPeriod(Client $client, Carbon $start, Carbon $end): Collection
    {
        return Payment::where('client_id', $client->id)
            ->whereBetween('payment_date', [$start, $end])
            ->where('status', Payment::STATUS_COMPLETED)
            ->orderBy('payment_date')
            ->get();
    }

    /**
     * Get closing balance as of a date
     */
    protected function getClosingBalance(Client $client, Carbon $asOfDate): float
    {
        $invoicesTotal = Invoice::where('client_id', $client->id)
            ->where('issue_date', '<=', $asOfDate)
            ->sum('total');

        $paymentsTotal = Payment::where('client_id', $client->id)
            ->where('payment_date', '<=', $asOfDate)
            ->where('status', Payment::STATUS_COMPLETED)
            ->sum('amount');

        return $invoicesTotal - $paymentsTotal;
    }

    /**
     * Build line items for statement
     */
    protected function buildLineItems(Collection $invoices, Collection $payments): array
    {
        $items = [];

        // Add invoices
        foreach ($invoices as $invoice) {
            $items[] = [
                'date' => $invoice->issue_date,
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'description' => "Invoice #{$invoice->invoice_number}",
                'amount' => $invoice->total,
                'balance' => $invoice->total,
            ];
        }

        // Add payments
        foreach ($payments as $payment) {
            $items[] = [
                'date' => $payment->payment_date,
                'type' => 'payment',
                'reference' => $payment->payment_number,
                'description' => "Payment #{$payment->payment_number}",
                'amount' => -$payment->amount, // Negative for payments
                'balance' => 0, // Will be calculated
            ];
        }

        // Sort by date
        usort($items, fn($a, $b) => $a['date']->timestamp <=> $b['date']->timestamp);

        return $items;
    }

    /**
     * Get all clients with outstanding balances
     */
    public function getClientsWithOutstandingBalances(): Collection
    {
        return Client::whereHas('invoices', function ($query) {
            $query->whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_VIEWED,
                Invoice::STATUS_PARTIALLY_PAID,
                Invoice::STATUS_OVERDUE,
            ]);
        })->get();
    }

    /**
     * Generate statements for all clients with outstanding balances
     */
    public function generateStatementsForAllClients(?Carbon $periodEnd = null): array
    {
        $clients = $this->getClientsWithOutstandingBalances();
        $statements = [];

        foreach ($clients as $client) {
            $statements[] = $this->generateStatement($client, $periodEnd);
        }

        return $statements;
    }
}
