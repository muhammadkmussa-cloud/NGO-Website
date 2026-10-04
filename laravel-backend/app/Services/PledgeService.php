<?php

namespace App\Services;

use App\Models\Pledge;
use App\Models\PledgePayment;
use App\Models\PledgePaymentAttempt;
use App\Services\Exceptions\PaymentGatewayException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Monthly M-Pesa pledge lifecycle: obligation creation (one row per billing
 * month), STK initiation via Paystack /charge, and idempotent webhook
 * settlement (spec §2–§4, §8–§9, §12–§13).
 *
 * Concurrency: every state transition that the spec makes authoritative
 * (PAID idempotency, no-downgrade on charge.failed, the cooldown window, the
 * anchor advance) happens under a row lock or as a conditional UPDATE, so
 * interleaved webhooks/double-clicks cannot race it.
 */
class PledgeService
{
    /** Re-send guard: no second STK prompt within this window (spec §18). */
    public const ATTEMPT_COOLDOWN_SECONDS = 60;

    /** Reuse a supporter's just-created pledge instead of duplicating it (double-clicks). */
    public const RECENT_DUPLICATE_MINUTES = 10;

    /** How often a pay link may rebind the pledge's phone number per hour. */
    public const PHONE_CHANGES_PER_HOUR = 2;

    /** An STK prompt older than this is dead (Paystack prompts die in ~60s). */
    public const ABANDONED_ATTEMPT_MINUTES = 15;

    public function __construct(
        private PaystackService $paystack,
        private MpesaService $mpesa,
        private AuditLogger $audit,
        private EmailService $email,
    ) {}

    /**
     * Creates the pledge (or reuses a matching one from the last 10 minutes —
     * double-click / retry safety, spec §18). Identity is email + normalized
     * phone + amount; a cross-request lock serializes check-then-create so two
     * concurrent submissions cannot both miss the reuse window.
     *
     * @return array{0: Pledge, 1: bool}
     */
    public function createPledge(array $data): array
    {
        $phone = $this->mpesa->formatPhone($data['phone']);
        $minor = (int) round(((float) $data['amount']) * 100);

        $create = function () use ($data, $phone, $minor): array {
            $recent = Pledge::where('email', (string) $data['email'])
                ->where('phone', $phone)
                ->where('method', Pledge::METHOD_MPESA)
                ->where('status', Pledge::STATUS_ACTIVE)
                ->whereRaw('ROUND(amount * 100) = ?', [$minor])
                ->where('created_at', '>=', now()->subMinutes(self::RECENT_DUPLICATE_MINUTES))
                ->latest('id')
                ->first();
            if ($recent) {
                return [$recent, false];
            }

            $now = now(config('roi.pledge_timezone'));

            $pledge = Pledge::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $phone,
                'amount' => (float) $data['amount'],
                'currency' => 'KES',
                'method' => Pledge::METHOD_MPESA,
                'channel' => 'mobile_money',
                'status' => Pledge::STATUS_ACTIVE,
                'start_date' => $now->toDateString(),
                'next_payment_date' => $now->toDateString(),
            ]);

            return [$pledge, true];
        };

        $lock = Cache::lock(
            'pledge-create:'.sha1(strtolower((string) $data['email']).'|'.$phone.'|'.$minor),
            10,
        );

        return $lock->block(5, $create);
    }

    /**
     * The single obligation row for a billing month (firstOrCreate against the
     * composite unique key — running this twice never duplicates a month; a
     * concurrent firstOrCreate loser re-fetches instead of 500ing).
     *
     * @param  string|null  $dueDate  explicit due date (Y-m-d) — the daily
     *                                backfill cron passes dueDateFor() so every
     *                                past month keeps its anchor day; defaults
     *                                to next_payment_date (store path).
     */
    public function ensureObligation(Pledge $pledge, ?string $billingMonth = null, ?string $dueDate = null): PledgePayment
    {
        $billingMonth ??= PledgePayment::billingMonthFor(now());
        $dueDate ??= ($pledge->next_payment_date ?? $pledge->start_date)
            ?->format('Y-m-d') ?? now(config('roi.pledge_timezone'))->toDateString();

        try {
            $payment = PledgePayment::firstOrCreate(
                ['pledge_id' => $pledge->id, 'billing_month' => $billingMonth],
                [
                    'amount_due' => $pledge->amount,
                    'currency' => $pledge->currency,
                    'due_date' => $dueDate,
                    'status' => PledgePayment::STATUS_DUE,
                    'method' => $pledge->method,
                    'channel' => $pledge->channel,
                ]
            );
        } catch (UniqueConstraintViolationException) {
            return PledgePayment::where('pledge_id', $pledge->id)
                ->where('billing_month', $billingMonth)
                ->firstOrFail();
        }

        if ($payment->wasRecentlyCreated) {
            // §21: one audit row per newly created monthly obligation —
            // the firstOrCreate loser (catch above) deliberately logs nothing.
            $this->audit->record(
                (string) $pledge->email,
                'Monthly obligation created',
                sprintf('pledge=%d month=%s due=%s', $pledge->id, $payment->billing_month, $payment->due_date)
            );
        }

        return $payment;
    }

    /**
     * Starts an M-Pesa request for one month's obligation: a NEW unique
     * reference and attempt row every time (spec §7.4, §8), unless a prompt is
     * already in flight (60s cooldown — double-click protection, §18).
     *
     * The cooldown read + attempt creation run under a row lock: two rapid
     * clicks serialize, the second sees the first attempt and reuses it. A
     * corrected phone number deliberately bypasses the cooldown (the old
     * prompt targeted the wrong number — §7).
     *
     * @return array{attempt: PledgePaymentAttempt, reused: bool, message: string}
     *
     * @throws PaymentGatewayException
     */
    public function initiateCharge(PledgePayment $payment, ?string $phone = null): array
    {
        $pledge = $payment->pledge;

        if (in_array($pledge->status, [Pledge::STATUS_CANCELLED, Pledge::STATUS_COMPLETED], true)) {
            throw new PaymentGatewayException('This pledge is no longer active.', 409);
        }
        if ($payment->status === PledgePayment::STATUS_PAID) {
            throw new PaymentGatewayException(
                "This month's pledge is already paid. Thank you!",
                409
            );
        }

        $normalizedPhone = $this->mpesa->formatPhone($phone ?? $pledge->phone);

        // Retire abandoned STK attempts (spec §8/§21) before judging the
        // cooldown; stale PENDING months fall back to DUE so they stay retryable.
        $this->expireAbandonedAttempts($payment);

        $decision = DB::transaction(function () use ($payment, $pledge, $normalizedPhone) {
            $locked = PledgePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PledgePayment::STATUS_PAID) {
                return ['already_paid' => true];
            }

            // Decide the phone change under the pledge lock too (lock order
            // stays payment -> pledge, matching settleFromWebhook), so a
            // concurrent correction can't flip the cooldown decision.
            $lockedPledge = Pledge::whereKey($pledge->id)->lockForUpdate()->firstOrFail();

            // §17: re-check status under the lock — a cancel acknowledged while
            // this request waited for the rows must not dispatch a fresh STK
            // prompt. Checked BEFORE the phone logic so a dead pledge never
            // consumes the rebind quota.
            if (in_array($lockedPledge->status, [Pledge::STATUS_CANCELLED, Pledge::STATUS_COMPLETED], true)) {
                return ['cancelled' => true];
            }

            $phoneChanged = $normalizedPhone !== (string) $lockedPledge->phone;
            if ($phoneChanged) {
                // A leaked token must not become an STK-spam cannon: cap how
                // often the number may be rebound (spec §18 — the cooldown
                // still applies once the cap is hit).
                $changesKey = 'pledge-phone-fix:'.$lockedPledge->id;
                $changes = (int) Cache::get($changesKey, 0);
                if ($changes >= self::PHONE_CHANGES_PER_HOUR) {
                    $phoneChanged = false;
                } else {
                    Cache::put($changesKey, $changes + 1, now()->addHour());
                    // §7: the supporter may correct their number on the pay page.
                    $lockedPledge->update(['phone' => $normalizedPhone]);
                }
            }

            if (! $phoneChanged) {
                $inFlight = $locked->paymentAttempts()
                    ->where('status', PledgePaymentAttempt::STATUS_INITIATED)
                    ->where('created_at', '>=', now()->subSeconds(self::ATTEMPT_COOLDOWN_SECONDS))
                    ->latest('id')
                    ->first();
                if ($inFlight && $locked->status === PledgePayment::STATUS_PENDING) {
                    return [
                        'attempt' => $inFlight,
                        'reused' => true,
                        'message' => 'M-Pesa request already sent. Check your phone and enter your M-Pesa PIN.',
                    ];
                }
            }

            $reference = PaystackService::makeReference('pledge');

            $attempt = PledgePaymentAttempt::create([
                'pledge_payment_id' => $locked->id,
                'pledge_id' => $pledge->id,
                'reference' => $reference,
                'amount' => $locked->amount_due,
                'currency' => $locked->currency,
                'method' => $locked->method,
                'channel' => 'mobile_money',
                'status' => PledgePaymentAttempt::STATUS_INITIATED,
                'initiated_at' => now(),
            ]);

            $locked->update([
                'status' => PledgePayment::STATUS_PENDING,
                'paystack_reference' => $reference,
                'attempts' => $locked->attempts + 1,
                'last_attempt_at' => now(),
            ]);

            // Prompt the PERSISTED number: when the rebind cap kicked in the
            // stored (old) number must win, never an attacker-chosen one.
            return [
                'attempt' => $attempt,
                'reused' => false,
                'reference' => $reference,
                'phone' => (string) $lockedPledge->phone,
            ];
        });

        if ($decision['cancelled'] ?? false) {
            throw new PaymentGatewayException('This pledge is no longer active.', 409);
        }
        if ($decision['already_paid'] ?? false) {
            throw new PaymentGatewayException(
                "This month's pledge is already paid. Thank you!",
                409
            );
        }
        if ($decision['reused'] ?? false) {
            return [
                'attempt' => $decision['attempt'],
                'reused' => true,
                'message' => $decision['message'],
            ];
        }

        try {
            $result = $this->paystack->chargeMobileMoney(
                (string) $pledge->email,
                (float) $payment->amount_due,
                (string) $payment->currency,
                (string) $decision['reference'],
                (string) $decision['phone'],
            );
        } catch (PaymentGatewayException $e) {
            // Gateway never accepted it — record the failed attempt (audit §21)
            // and leave the month unpaid and retryable (§4). Both writes are
            // conditional so a late/concurrent settle can't be clobbered.
            $attemptChanged = PledgePaymentAttempt::whereKey($decision['attempt']->id)
                ->where('status', '!=', PledgePaymentAttempt::STATUS_SUCCEEDED)
                ->update([
                    'status' => PledgePaymentAttempt::STATUS_FAILED,
                    'failure_reason' => substr($e->getMessage(), 0, 191),
                    'responded_at' => now(),
                ]) > 0;
            $paymentChanged = PledgePayment::whereKey($payment->id)
                ->where('status', PledgePayment::STATUS_PENDING)
                ->update([
                    'status' => PledgePayment::STATUS_DUE,
                    'paystack_reference' => null,
                ]) > 0;

            // §21: audit only a real transition — if a charge.success webhook
            // settled this reference while our call timed out after gateway
            // acceptance, both writes matched 0 rows and a "failed" row here
            // would falsify the trail (the payment itself stays PAID either way).
            if ($attemptChanged || $paymentChanged) {
                $this->audit->record(
                    (string) $pledge->email,
                    'Payment failed',
                    sprintf(
                        'reference=%s month=%s reason=%s',
                        $decision['reference'],
                        $payment->billing_month,
                        substr($e->getMessage(), 0, 120),
                    )
                );
            }

            throw $e;
        }

        // §21: a prompt actually left the system — log the attempt reference.
        $this->audit->record(
            (string) $pledge->email,
            'Payment initiated',
            sprintf(
                'reference=%s month=%s attempt=%d',
                $decision['reference'],
                $payment->billing_month,
                $decision['attempt']->id,
            )
        );

        return [
            'attempt' => $decision['attempt'],
            'reused' => false,
            'message' => $result['message'],
        ];
    }

    /**
     * Retires abandoned prompts: initiated attempts older than
     * ABANDONED_ATTEMPT_MINUTES become `expired`, and PENDING months whose
     * prompt is gone return to `DUE` so billing can retry (spec §4/§21).
     * Called opportunistically from initiateCharge and safe for the Step 4
     * cron to call as a sweeper.
     */
    public function expireAbandonedAttempts(?PledgePayment $payment = null): int
    {
        $cutoff = now()->subMinutes(self::ABANDONED_ATTEMPT_MINUTES);

        $attempts = PledgePaymentAttempt::where('status', PledgePaymentAttempt::STATUS_INITIATED)
            ->where('created_at', '<', $cutoff);
        if ($payment) {
            $attempts->where('pledge_payment_id', $payment->id);
        }
        $expired = $attempts->update([
            'status' => PledgePaymentAttempt::STATUS_EXPIRED,
            'responded_at' => now(),
        ]);

        // Conditional on PENDING: never touches a just-settled PAID row.
        $stale = PledgePayment::where('status', PledgePayment::STATUS_PENDING)
            ->whereNotNull('last_attempt_at')
            ->where('last_attempt_at', '<', $cutoff);
        if ($payment) {
            $stale->where('id', $payment->id);
        }
        $stale->update([
            'status' => PledgePayment::STATUS_DUE,
            'paystack_reference' => null,
        ]);

        return $expired;
    }

    /**
     * Idempotent webhook settlement for pledge references (ROI-PLE-*).
     * charge.success verifies amount + currency before marking anything PAID
     * under a row lock; charge.failed is a conditional write that can never
     * downgrade PAID (§9). Replays are no-ops.
     */
    public function settleFromWebhook(string $reference, array $data, string $event): void
    {
        $attempt = PledgePaymentAttempt::where('reference', $reference)->first();
        if (! $attempt) {
            report("Pledge webhook for unknown reference: {$reference} (event {$event})");

            return;
        }

        $payment = $attempt->pledgePayment;
        $pledge = $attempt->pledge;
        if (! $payment || ! $pledge) {
            report("Pledge webhook for orphaned attempt: {$reference}");

            return;
        }

        // Belt-and-braces: the attempt must actually belong to that payment's
        // pledge before we advance schedule state on either row.
        if ((int) $payment->pledge_id !== (int) $attempt->pledge_id) {
            report("Pledge webhook attempt/payment pledge mismatch: {$reference}");

            return;
        }

        if ($event === 'charge.failed') {
            $this->markFailed($attempt, $payment);

            return;
        }

        if ($event !== 'charge.success') {
            return;
        }

        // Idempotent replay fast path: already settled — acknowledge untouched.
        if ($payment->status === PledgePayment::STATUS_PAID) {
            return;
        }

        // Spec §9.4: confirm expected amount + currency before trusting the event.
        $expectedMinor = (int) round(((float) $payment->amount_due) * 100);
        $gotMinor = isset($data['amount']) ? (int) $data['amount'] : null;
        $gotCurrency = (string) ($data['currency'] ?? '');
        if ($gotMinor !== $expectedMinor || $gotCurrency !== (string) $payment->currency) {
            report(sprintf(
                'Pledge settle REJECTED (amount/currency mismatch): ref=%s expected=%d %s got=%s %s',
                $reference,
                $expectedMinor,
                $payment->currency,
                $gotMinor === null ? 'null' : (string) $gotMinor,
                $gotCurrency === '' ? 'null' : $gotCurrency,
            ));

            return;
        }

        $transactionId = isset($data['id']) ? (string) $data['id'] : null;

        $settled = DB::transaction(function () use ($attempt, $payment, $pledge, $transactionId) {
            $locked = PledgePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PledgePayment::STATUS_PAID) {
                // A concurrent replay settled it first — spec §9 no-op.
                return false;
            }

            PledgePaymentAttempt::whereKey($attempt->id)
                ->where('status', '!=', PledgePaymentAttempt::STATUS_SUCCEEDED)
                ->update([
                    'status' => PledgePaymentAttempt::STATUS_SUCCEEDED,
                    'paystack_transaction_id' => $transactionId,
                    'responded_at' => now(),
                ]);

            $locked->update([
                'status' => PledgePayment::STATUS_PAID,
                'paid_at' => now(),
                'paystack_transaction_id' => $transactionId,
            ]);

            // Lock the pledge before the anchor read-modify-write (§12).
            $lockedPledge = Pledge::whereKey($pledge->id)->lockForUpdate()->firstOrFail();

            $today = now(config('roi.pledge_timezone'))->toDateString();
            $last = $lockedPledge->last_successful_payment_date?->format('Y-m-d');
            if ($last === null || $last < $today) {
                $lockedPledge->last_successful_payment_date = $today;
            }

            // Advance the anchor-based schedule, never backwards (the scheduler
            // may already be ahead). Compare Y-m-d strings: the date cast is
            // UTC-midnight while nextAnchorAfter returns Nairobi — instants
            // would mix timezones.
            $next = $this->nextAnchorAfter($lockedPledge, $locked->billing_month)->toDateString();
            $current = $lockedPledge->next_payment_date?->format('Y-m-d');
            if ($current === null || $current < $next) {
                $lockedPledge->next_payment_date = $next;
            }

            $lockedPledge->save();

            return true;
        });

        if ($settled) {
            // Pick up the PAID/paid_at written by the transaction above.
            $payment->refresh();

            $this->audit->record(
                (string) $pledge->email,
                'Pledge payment settled',
                sprintf('reference=%s month=%s amount=%s %s', $reference, $payment->billing_month, $payment->amount_due, $payment->currency)
            );

            // Exactly-one confirmation email per settled month (ledger-gated);
            // never throws, so a mail outage can't fail the webhook (§9).
            $this->email->sendConfirmation($payment);
        }
    }

    /**
     * Spec §4: a failed/expired charge leaves the month outstanding and
     * retryable. Both writes are conditional — PAID is never downgraded.
     */
    private function markFailed(PledgePaymentAttempt $attempt, PledgePayment $payment): void
    {
        $changed = DB::transaction(function () use ($attempt, $payment) {
            // Lock order payment -> attempt, mirroring settleFromWebhook, so a
            // concurrent charge.success + charge.failed on the same reference
            // cannot deadlock (one blocks, the other completes idempotently).
            $paymentChanged = PledgePayment::whereKey($payment->id)
                ->where('status', PledgePayment::STATUS_PENDING)
                ->update(['status' => PledgePayment::STATUS_FAILED]) > 0;

            $attemptChanged = PledgePaymentAttempt::whereKey($attempt->id)
                ->whereIn('status', [
                    PledgePaymentAttempt::STATUS_INITIATED,
                    PledgePaymentAttempt::STATUS_EXPIRED,
                ])
                ->update([
                    'status' => PledgePaymentAttempt::STATUS_FAILED,
                    'failure_reason' => 'Charge failed',
                    'responded_at' => now(),
                ]) > 0;

            return $paymentChanged || $attemptChanged;
        });

        // §21: audit only on a real transition — a charge.failed webhook
        // replay must not pile up duplicate failure rows.
        if ($changed) {
            $this->audit->record(
                (string) $attempt->pledge->email,
                'Payment failed',
                sprintf('reference=%s month=%s reason=Charge failed', $attempt->reference, $payment->billing_month)
            );
        }
    }

    /**
     * Spec §17: the supporter cancels through their own secure pay link.
     * Status flips to CANCELLED — bill, remind and initiateCharge all gate on
     * it — while every historical payment/attempt row stays intact. A prompt
     * already in flight may still settle via webhook afterwards (money taken
     * must be recorded; only FUTURE obligations stop). Idempotent: cancelling
     * twice is acknowledged, never an error.
     */
    public function cancelFromPayment(PledgePayment $payment): void
    {
        $cancelled = DB::transaction(function () use ($payment) {
            $locked = Pledge::whereKey($payment->pledge_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === Pledge::STATUS_CANCELLED) {
                return null;
            }
            $locked->update(['status' => Pledge::STATUS_CANCELLED]);

            return $locked;
        });

        if ($cancelled) {
            $this->audit->record(
                (string) $cancelled->email,
                'Pledge cancelled',
                sprintf('pledge=%d source=secure-pay-link via-month=%s', $cancelled->id, $payment->billing_month)
            );
        }
    }

    /**
     * Next occurrence of the pledge's anchor day (start_date day-of-month,
     * clamped to month length) after the paid billing month (§12).
     */
    public function nextAnchorAfter(Pledge $pledge, string $paidMonth): Carbon
    {
        [$year, $month] = array_map('intval', explode('-', $paidMonth));
        $target = Carbon::create($year, $month + 1, 1, 0, 0, 0, config('roi.pledge_timezone'));

        return $this->anchorIn($pledge, $target);
    }

    /**
     * Due date of the obligation for an arbitrary billing month: the pledge's
     * anchor day clamped to that month's length, in the pledge timezone.
     * Shared by the daily backfill cron (§13) and nextAnchorAfter (§12).
     */
    public function dueDateFor(Pledge $pledge, string $billingMonth): Carbon
    {
        [$year, $month] = array_map('intval', explode('-', $billingMonth));
        $target = Carbon::create($year, $month, 1, 0, 0, 0, config('roi.pledge_timezone'));

        return $this->anchorIn($pledge, $target);
    }

    private function anchorIn(Pledge $pledge, Carbon $monthStart): Carbon
    {
        $anchorDay = max(1, (int) ($pledge->start_date?->day ?? 1));

        return $monthStart->setDate(
            $monthStart->year,
            $monthStart->month,
            min($anchorDay, $monthStart->daysInMonth()),
        );
    }
}
