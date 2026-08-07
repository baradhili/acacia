<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceViewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invoice $invoice
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Invoice {$this->invoice->invoice_number} viewed")
            ->line("We wanted to let you know that invoice {$this->invoice->invoice_number} for {$this->invoice->formatted_total} has been viewed.")
            ->line("Invoice Date: {$this->invoice->invoice_date->format('d M Y')}")
            ->line("Due Date: {$this->invoice->due_date->format('d M Y')}")
            ->action('View Invoice', url("/invoices/{$this->invoice->id}"))
            ->line('Thank you for your business!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'amount' => $this->invoice->total,
            'viewed_at' => now()->toIso8601String(),
        ];
    }
}
