<?php

namespace App\Mail;

use App\Models\Pledge;
use App\Models\PledgePayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Payment confirmation (plan A2): sent exactly once per settled obligation
 * (idempotent via the PledgeEmailAttempt ledger, spec §11). HTML + plain text.
 */
class PledgeConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PledgePayment $payment,
        public Pledge $pledge,
        public string $monthLabel,
        public string $amountLabel,
        public ?string $paidAtLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment received — {$this->monthLabel} pledge ({$this->amountLabel})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pledges.confirmation',
            text: 'emails.pledges.confirmation-text',
            with: [
                'name' => $this->pledge->name ?: 'Supporter',
                'siteName' => (string) config('app.name'),
                'supportEmail' => (string) config('mail.from.address'),
            ],
        );
    }
}
