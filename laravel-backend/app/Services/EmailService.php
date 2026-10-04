<?php

namespace App\Services;

use App\Mail\PledgeConfirmationMail;
use App\Mail\PledgeReminderMail;
use App\Models\PledgeEmailAttempt;
use App\Models\PledgePayment;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Outbound supporter email for the monthly pledge (spec §11 + plan A2/A4).
 *
 * Channel-shaped by design: callers pass a channel name, so an SMS channel
 * can slot in later without touching call sites — today only 'email' exists.
 *
 * Exactly-once per (payment, kind, reminder_key): a per-key cache lock
 * serialises the whole read-gate → send → record sequence (the ledger row is
 * created 'failed' BEFORE the mailer returns, so without the lock a second
 * worker could read it as a retry and double-send), and the DB unique
 * constraint behind it catches any race the lock cannot (§1). A 'failed' row
 * is retried on the next run (SMTP outages heal themselves).
 *
 * Nothing here ever throws: every path is wrapped, so a webhook settlement
 * or cron run can never be failed by an inbox, a ledger write, or a database
 * blip being down.
 */
class EmailService
{
    public const CHANNEL_EMAIL = 'email';

    public const TEMPLATE_REMINDER = 'reminder';

    public const TEMPLATE_CONFIRMATION = 'confirmation';

    public function __construct(private readonly PledgeTokenService $tokens) {}

    /** Reminder with a secure pay link (spec §11; keys like 'd-1', 'd-3', 'd-7'). */
    public function sendReminder(PledgePayment $payment, string $reminderKey): bool
    {
        return $this->send(
            self::CHANNEL_EMAIL,
            self::TEMPLATE_REMINDER,
            $payment,
            ['reminder_key' => $reminderKey],
        );
    }

    /** One payment-confirmation email per settled month (plan A2). */
    public function sendConfirmation(PledgePayment $payment): bool
    {
        return $this->send(self::CHANNEL_EMAIL, self::TEMPLATE_CONFIRMATION, $payment);
    }

    /**
     * Channel-shaped dispatch (plan A4).
     *
     * @param  array<string, mixed>  $context  e.g. ['reminder_key' => 'd-3']
     * @return bool true when the message was accepted by the mailer
     */
    public function send(string $channel, string $template, PledgePayment $payment, array $context = []): bool
    {
        if ($channel !== self::CHANNEL_EMAIL) {
            report("EmailService: unsupported channel [{$channel}] (template {$template})");

            return false;
        }

        $kind = $template === self::TEMPLATE_CONFIRMATION
            ? PledgeEmailAttempt::KIND_CONFIRMATION
            : PledgeEmailAttempt::KIND_REMINDER;
        $reminderKey = (string) ($context['reminder_key'] ?? '');

        try {
            // Serialize gate + send per key (§11): the ledger row exists as
            // 'failed' while SMTP is still in flight, so without this lock a
            // second worker could read it as a retry and double-send.
            // TTL 120s > the ~60s default SMTP socket timeout, so the lock
            // can never expire mid-send and open the gate for a second worker.
            return Cache::lock(
                "pledge-email:{$payment->id}:{$kind}:{$reminderKey}",
                120,
            )->block(5, function () use ($payment, $template, $kind, $reminderKey) {
                $payment->loadMissing('pledge');
                $pledge = $payment->pledge;
                if (! $pledge || ! $pledge->email) {
                    return false;
                }

                // Never nag about a month that has been paid (spec §11).
                if ($template === self::TEMPLATE_REMINDER
                    && $payment->status === PledgePayment::STATUS_PAID) {
                    return false;
                }
                // ...and never claim a payment that hasn't settled.
                if ($template === self::TEMPLATE_CONFIRMATION
                    && $payment->status !== PledgePayment::STATUS_PAID) {
                    return false;
                }

                // ---------- ledger gate: exactly-once per (payment, kind, key) ----------
                $ledger = PledgeEmailAttempt::query()
                    ->where('pledge_payment_id', $payment->id)
                    ->where('kind', $kind)
                    ->where('reminder_key', $reminderKey)
                    ->first();

                if ($ledger?->status === PledgeEmailAttempt::STATUS_SENT) {
                    return false; // already out the door
                }

                if (! $ledger) {
                    try {
                        $ledger = PledgeEmailAttempt::create([
                            'pledge_id' => $payment->pledge_id,
                            'pledge_payment_id' => $payment->id,
                            'kind' => $kind,
                            'reminder_key' => $reminderKey,
                            // Placeholder until the mailer accepts it — flipped
                            // to 'sent' below (model default would claim
                            // success before the send happened).
                            'status' => PledgeEmailAttempt::STATUS_FAILED,
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        return false; // a lockless race still lost the key
                    }
                }

                // ---------- build + send + record (all guarded) ----------
                try {
                    $monthLabel = Carbon::createFromFormat('Y-m', $payment->billing_month)->format('F Y');
                    $amountLabel = $this->amountLabel($payment);

                    $mailable = match ($template) {
                        self::TEMPLATE_REMINDER => new PledgeReminderMail(
                            $payment,
                            $pledge,
                            $this->payUrl($payment),
                            $reminderKey,
                            $monthLabel,
                            $amountLabel,
                        ),
                        self::TEMPLATE_CONFIRMATION => new PledgeConfirmationMail(
                            $payment,
                            $pledge,
                            $monthLabel,
                            $amountLabel,
                            $this->paidAtLabel($payment),
                        ),
                        default => throw new InvalidArgumentException("EmailService: unknown template [{$template}]"),
                    };

                    Mail::to((string) $pledge->email)->send($mailable);

                    // Guarded: a DB blip here must not rethrow a webhook after
                    // money settled, and must not strand a delivered mail as
                    // 'failed' (that would re-send on the next cron run).
                    $ledger->update([
                        'status' => PledgeEmailAttempt::STATUS_SENT,
                        'sent_at' => now(),
                        'error' => null,
                    ]);

                    return true;
                } catch (Throwable $e) {
                    try {
                        $ledger->update([
                            'status' => PledgeEmailAttempt::STATUS_FAILED,
                            'error' => Str::limit($e->getMessage(), 191, ''),
                        ]);
                    } catch (Throwable $writeFailure) {
                        report($writeFailure);
                    }
                    report($e);

                    return false;
                }
            });
        } catch (LockTimeoutException) {
            // Another worker owns this key right now — it records the result.
            return false;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Absolute, tokenised pay page URL (spec §6/§11). */
    private function payUrl(PledgePayment $payment): string
    {
        return route('pledges.pay', ['token' => $this->tokens->mint($payment)], true);
    }

    private function amountLabel(PledgePayment $payment): string
    {
        $amount = number_format((float) $payment->amount_due, 2);

        return match ($payment->currency) {
            'KES' => 'KSh '.$amount,
            'USD' => '$'.$amount,
            default => $payment->currency.' '.$amount,
        };
    }

    /** Paid-at shown in the pledge timezone (spec §19), not UTC. */
    private function paidAtLabel(PledgePayment $payment): ?string
    {
        if (! $payment->paid_at) {
            return null;
        }

        return $payment->paid_at
            ->copy()
            ->setTimezone((string) config('roi.pledge_timezone', 'Africa/Nairobi'))
            ->format('d M Y, H:i');
    }
}
