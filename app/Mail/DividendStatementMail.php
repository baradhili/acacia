<?php

namespace App\Mail;

use App\Models\CompanyProfile;
use App\Models\DividendDistribution;
use Barryvdh\DomPDF\Facade\Pdf;
use IFRS\Models\Entity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Per-shareholder dividend statement with the franking credit details the
 * recipient needs for their own tax return. Mirrors InvoiceMail: the PDF
 * is rendered from reports/pdf/dividend-statement, attached from tmp
 * storage and cleaned up after send.
 */
class DividendStatementMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public DividendDistribution $distribution)
    {
        $this->distribution->loadMissing('declaration.shareClass', 'shareholder');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Dividend Statement ' . $this->distribution->declaration->declaration_number
                . ' from ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dividend-statement',
            with: [
                'distribution' => $this->distribution,
                'companyName' => $this->companyName(),
                'companyAbn' => CompanyProfile::effectiveAbn($this->distribution->declaration->entity_id),
            ],
        );
    }

    public function attachments(): array
    {
        $pdf = Pdf::loadView('reports.pdf.dividend-statement', [
            'distribution' => $this->distribution,
            'companyName' => $this->companyName(),
            'companyAbn' => CompanyProfile::effectiveAbn($this->distribution->declaration->entity_id),
        ]);

        $filename = 'Dividend-Statement-' . $this->distribution->declaration->declaration_number
            . '-' . $this->distribution->company_shareholder_id . '.pdf';

        Storage::put("tmp/{$filename}", $pdf->output());

        $this->withSwiftMessage(function ($message) use ($filename) {
            $message->afterSend(function () use ($filename) {
                if (Storage::exists("tmp/{$filename}")) {
                    Storage::delete("tmp/{$filename}");
                }
            });
        });

        return [
            Attachment::fromStorage("tmp/{$filename}")
                ->as($filename)
                ->withMimeType('application/pdf'),
        ];
    }

    protected function companyName(): string
    {
        $entity = Entity::find($this->distribution->declaration->entity_id);

        return $entity?->name ?? config('app.name');
    }
}
