<?php

namespace App\Console\Commands;

use App\Models\Pledge;
use App\Models\PledgeEmailAttempt;
use App\Models\PledgePayment;
use App\Services\AuditLogger;
use App\Services\EmailService;
use App\Services\PledgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Hourly reminder pass (spec §11, §19; §22 test #6).
 *
 * 1. Retires abandoned STK prompts first (spec §8/§21): stale PENDING months
 *    fall back to DUE, so a supporter who ignored the prompt can still be
 *    reached by today's reminder.
 * 2. For each configured offset in PLEDGE_REMINDER_DAYS (days AFTER the due
 *    date), emails unpaid mobile-money pledges whose due_date + offset lands
 *    on today in the pledge timezone — batched, and at most one send per
 *    (payment, key): the EmailService ledger makes re-runs no-ops (§11
 *    "no spam"). PAID, PENDING, cancelled/paused, and card pledges are never
 *    contacted.
 */
class PledgeRemindCommand extends Command
{
    protected $signature = 'roi:pledges-remind';

    protected $description = 'Retire abandoned STK prompts and email unpaid mobile-money pledges per PLEDGE_REMINDER_DAYS (spec §11)';

    public function handle(
        PledgeService $pledges,
        EmailService $emails,
        AuditLogger $audit,
    ): int {
        $expired = $pledges->expireAbandonedAttempts();

        // §20 kill switch: while payments are off, outbound reminder email
        // stops too (the pay links would 503 on submission anyway). The
        // expiry sweep above stays — it is pure bookkeeping that keeps
        // PENDING months truthful during the outage.
        if (! config('roi.payments_enabled')) {
            $this->info('PAYMENTS_ENABLED is false — swept expired prompts, skipping reminder sends.');

            return self::SUCCESS;
        }

        $today = now((string) config('roi.pledge_timezone'))->toDateString();
        $batch = (int) config('roi.pledge_email_batch_size');
        $offsets = (array) config('roi.pledge_reminder_days', [1, 3, 7]);

        $due = 0;
        $sent = 0;
        $notSent = 0;

        foreach ($offsets as $days) {
            $key = 'd-'.(int) $days;
            $dueDate = Carbon::parse($today)
                ->subDays((int) $days)
                ->toDateString();

            $candidates = PledgePayment::query()
                ->with('pledge')
                // Half-open range: the DATE column serializes as 'Y-m-d 00:00:00'
                // on sqlite and as 'Y-m-d' on MySQL — both compare correctly
                // here, and the (status, due_date) index stays usable.
                ->where('due_date', '>=', $dueDate)
                ->where('due_date', '<', Carbon::parse($dueDate)->addDay()->toDateString())
                ->whereIn('status', [
                    PledgePayment::STATUS_DUE,
                    PledgePayment::STATUS_FAILED,
                    PledgePayment::STATUS_MISSED,
                ])
                ->whereHas('pledge', function ($query) {
                    $query->where('status', Pledge::STATUS_ACTIVE)
                        ->where('method', Pledge::METHOD_MPESA);
                })
                ->whereDoesntHave('emailAttempts', function ($query) use ($key) {
                    $query->where('kind', PledgeEmailAttempt::KIND_REMINDER)
                        ->where('reminder_key', $key)
                        ->where('status', PledgeEmailAttempt::STATUS_SENT);
                })
                ->orderBy('id')
                ->limit($batch)
                ->get();

            foreach ($candidates as $payment) {
                $due++;

                if ($emails->sendReminder($payment, $key)) {
                    $sent++;
                    $payment->fill([
                        'reminder_count' => (int) $payment->reminder_count + 1,
                        'last_reminder_at' => now(),
                    ])->save();
                    $audit->record(
                        (string) $payment->pledge->email,
                        'Pledge reminder email sent',
                        sprintf(
                            'key=%s month=%s amount=%s %s',
                            $key,
                            $payment->billing_month,
                            $payment->amount_due,
                            $payment->currency,
                        ),
                    );
                } else {
                    // Locked-out / failed / re-claimed elsewhere — the ledger
                    // row decides the next run; never double-send (§11).
                    $notSent++;
                }
            }
        }

        $this->info(sprintf(
            'stale prompts expired=%d; reminders due=%d sent=%d not-sent=%d',
            $expired,
            $due,
            $sent,
            $notSent,
        ));

        return self::SUCCESS;
    }
}
