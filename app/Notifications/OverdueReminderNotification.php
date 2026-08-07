<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OverdueReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public int $daysOverdue
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Reminder: Invoice {$this->invoice->invoice_number} is overdue")
            ->greeting("Hello {$notifiable->name},")
            ->line("This is a friendly reminder that invoice {$this->invoice->invoice_number} for {$this->invoice->formatted_total} is overdue by {$this->daysOverdue} days.")
            ->line("**Invoice Details:**")
            ->line("- Invoice Number: {$this->invoice->invoice_number}")
            ->line("- Invoice Date: {$this->invoice->invoice_date->format('d M Y')}")
            ->line("- Due Date: {$this->invoice->due_date->format('d M Y')}")
            ->line("- Amount Due: {$this->invoice->formatted_amount_due}");

        if ($this->invoice->amount_paid > 0) {
            $message->line("- Amount Paid: {$this->invoice->formatted_amount_paid}");
        }

        $message->line("Please arrange payment at your earliest convenience to avoid any further delays.");

        return $message
            ->action('View Invoice', url("/invoices/{$this->invoice->id}"))
            ->action('Pay Now', url("/invoices/{$this->invoice->id}/pay"))
            ->line('If you have already made payment, please disregard this reminder or contact us to confirm receipt.')
            ->line('Thank you for your attention to this matter.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'amount_due' => $this->invoice->amount_due,
            'days_overdue' => $this->daysOverdue,
            'due_date' => $this->invoice->due_date->toIso8601String(),
            'reminder_sent_at' => now()->toIso8601String(),
        ];
    }
}
