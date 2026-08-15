<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\OverdueReminderNotification;
use App\Notifications\PaymentReceivedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

class InvoiceNotificationService
{
    /**
     * Send payment received notification to client and admin
     */
    public function sendPaymentReceivedNotification(Payment $payment, ?Invoice $invoice = null): void
    {
        try {
            // Send to client
            if ($payment->client && $payment->client->email) {
                Notification::send($payment->client, new PaymentReceivedNotification($payment, $invoice));
            }

            // Send to admins
            $admins = User::role('admin')->get();
            foreach ($admins as $admin) {
                Notification::send($admin, new PaymentReceivedNotification($payment, $invoice));
            }

            Log::info('Payment received notification sent', [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'client_id' => $payment->client_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send payment received notification', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send overdue reminder notification to client and admin
     */
    public function sendOverdueReminderNotification(Invoice $invoice, int $daysOverdue): void
    {
        try {
            // Send to client
            if ($invoice->client && $invoice->client->email) {
                Notification::send($invoice->client, new OverdueReminderNotification($invoice, $daysOverdue));
            }

            // Send to admins
            $admins = User::role('admin')->get();
            foreach ($admins as $admin) {
                Notification::send($admin, new OverdueReminderNotification($invoice, $daysOverdue));
            }

            Log::info('Overdue reminder notification sent', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'days_overdue' => $daysOverdue,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send overdue reminder notification', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get notification statistics
     */
    public function getNotificationStats(): array
    {
        return [
            'payment_received' => \App\Models\Notification::where('type', PaymentReceivedNotification::class)->count(),
            'overdue_reminder' => \App\Models\Notification::where('type', OverdueReminderNotification::class)->count(),
        ];
    }
}
