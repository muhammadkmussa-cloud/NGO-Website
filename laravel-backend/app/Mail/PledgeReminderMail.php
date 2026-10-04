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
 * Monthly-pledge reminder (spec §11): month + amount + a secure pay link
 * (/pledges/pay/{token}) rendered in both HTML and plain text (§20).
 */
class PledgeReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PledgePayment $payment,
        public Pledge $pledge,
        public string $payUrl,
        public string $reminderKey,
        public string $monthLabel,
        public string $amountLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reminder: your {$this->monthLabel} pledge of {$this->amountLabel} is due",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pledges.reminder',
            text: 'emails.pledges.reminder-text',
            with: [
                'name' => $this->pledge->name ?: 'Supporter',
                'siteName' => (string) config('app.name'),
                'supportEmail' => (string) config('mail.from.address'),
            ],
        );
    }
}
