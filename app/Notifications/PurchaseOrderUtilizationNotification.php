<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseOrderUtilizationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PurchaseOrder $purchaseOrder,
        public float $utilization,
        public string $threshold
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->threshold === '100'
            ? "PO {$this->purchaseOrder->po_number} Fully Utilized"
            : "PO {$this->purchaseOrder->po_number} at {$this->threshold}% Utilization";

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line("Purchase Order **{$this->purchaseOrder->po_number}** has reached **{$this->threshold}%** utilization.");

        if ($this->threshold === '80') {
            $message->line("Remaining budget: **\${$this->purchaseOrder->remaining}**");
        } else {
            $message->line("This PO is now fully utilized.");
        }

        $message->action('View Purchase Order', url("/purchase-orders/{$this->purchaseOrder->id}"))
            ->line("Project: {$this->purchaseOrder->title}")
            ->line("Client: {$this->purchaseOrder->client->name}");

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'po_id' => $this->purchaseOrder->id,
            'po_number' => $this->purchaseOrder->po_number,
            'utilization' => $this->utilization,
            'threshold' => $this->threshold,
        ];
    }
}
