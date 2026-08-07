<?php

namespace App\Mail;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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

    public function build(): self
    {
        return $this->subject("Your Statement for {$this->statementData['period_label']}")
            ->view('emails.client-statement')
            ->with([
                'client' => $this->client,
                'statement' => $this->statementData,
            ]);
    }
}
