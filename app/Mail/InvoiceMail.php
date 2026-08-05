<?php

namespace App\Mail;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ?string $customSubject = null,
        public ?string $customBody = null
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->customSubject ?? "Invoice {$this->invoice->invoice_number} from " . config('app.name');
        
        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $defaultBody = $this->customBody ?? "Please find attached invoice {$this->invoice->invoice_number} for {$this->invoice->formatted_total}. Payment is due by {$this->invoice->due_date->format('d M Y')}.";

        return new Content(
            view: 'emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'body' => $defaultBody,
            ],
        );
    }

    public function attachments(): array
    {
        $attachments = [];

        // Generate PDF
        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $this->invoice,
        ]);

        $filename = "Invoice-{$this->invoice->invoice_number}.pdf";
        
        // Save to temporary storage
        Storage::put("tmp/{$filename}", $pdf->output());

        $attachments[] = Attachment::fromStorage("tmp/{$filename}")
            ->as($filename)
            ->withMimeType('application/pdf');

        return $attachments;
    }

    public function build(): self
    {
        // Clean up temp files after email is built
        $this->withSwiftMessage(function ($message) {
            $message->afterSend(function () {
                $filename = "tmp/Invoice-{$this->invoice->invoice_number}.pdf";
                if (Storage::exists($filename)) {
                    Storage::delete($filename);
                }
            });
        });

        return $this->subject($this->customSubject ?? "Invoice {$this->invoice->invoice_number}")
            ->view('emails.invoice')
            ->with([
                'invoice' => $this->invoice,
                'body' => $this->customBody ?? "Please find attached invoice {$this->invoice->invoice_number}.",
            ]);
    }
}
