<?php

namespace App\Mail;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Monthly statement email. The statements:send command (scheduled the
 * 1st of each month at 09:00) sends it synchronously to each client
 * with an email address and an outstanding balance. Carries the Client
 * plus ClientStatementService's statement array — period label,
 * opening and closing balances, invoiced and paid totals — and no
 * attachments.
 */
class ClientStatementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Client $client,
        public array $statementData
    ) {}

    public function envelope(): Envelope
    {
        $period = $this->statementData['period_label'] ?? date('F Y');

        return new Envelope(
            subject: "Statement for {$period}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-statement',
            with: [
                'client' => $this->client,
                'statement' => $this->statementData,
            ],
        );
    }
}
