<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Payment $payment,
        public ?string $customSubject = null
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->customSubject ?? "Payment Receipt {$this->payment->payment_number} from " . config('app.name');
        
        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-receipt',
            with: [
                'payment' => $this->payment,
            ],
        );
    }

    public function build(): self
    {
        return $this->subject($this->customSubject ?? "Payment Receipt {$this->payment->payment_number}")
            ->view('emails.payment-receipt')
            ->with([
                'payment' => $this->payment,
            ]);
    }
}
