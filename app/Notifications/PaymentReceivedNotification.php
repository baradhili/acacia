<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Payment $payment,
        public ?Invoice $invoice = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Payment Received - {$this->payment->formatted_amount}")
            ->line("Thank you! We have received your payment of {$this->payment->formatted_amount}.")
            ->line("Payment Reference: {$this->payment->payment_number}")
            ->line("Payment Date: {$this->payment->payment_date->format('d M Y')}")
            ->line("Payment Method: {$this->payment->formatted_method}");

        if ($this->invoice) {
            $message->line("This payment has been applied to invoice {$this->invoice->invoice_number}.");
            
            if ($this->invoice->amount_due > 0) {
                $message->line("Remaining balance due: {$this->invoice->formatted_amount_due}");
            } else {
                $message->line("This invoice has been paid in full. Thank you!");
            }
        }

        return $message
            ->action('View Payment Details', url("/payments/{$this->payment->id}"))
            ->line('Thank you for your business!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'payment_id' => $this->payment->id,
            'payment_number' => $this->payment->payment_number,
            'amount' => $this->payment->amount,
            'invoice_id' => $this->invoice?->id,
            'received_at' => now()->toIso8601String(),
        ];
    }
}
