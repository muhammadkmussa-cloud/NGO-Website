<?php

namespace App\Console\Commands;

use App\Models\Pledge;
use App\Models\PledgePayment;
use App\Services\PledgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Daily billing pass (spec §11/§12/§13, §19, §22 tests #1/#12/#19).
 *
 * For every ACTIVE M-Pesa pledge:
 *  - backfills one obligation row per billing month from start_date to the
 *    current Nairobi month, each due on the pledge's anchor day (§13);
 *  - retires unpaid past months as MISSED — visible forever, never merged
 *    away, never silently marked paid (§13);
 *  - on the due day itself, starts the initial M-Pesa request for that
 *    month's obligation (spec §11 step 1), exactly one attempt per month.
 *
 * Safe to run repeatedly (firstOrCreate + attempts=0 gate + initiateCharge
 * cooldown), which is what the daily scheduler and §22 require.
 */
class PledgeBillCommand extends Command
{
    protected $signature = 'roi:pledges-bill';

    protected $description = 'Backfill monthly pledge obligations, retire unpaid past months as MISSED, and start the due-day M-Pesa request (spec §11/§13)';

    public function handle(PledgeService $pledges): int
    {
        // §20 kill switch: with payments disabled, no obligation may be
        // created and no M-Pesa request may leave the system — the flag must
        // freeze the scheduler, not only the HTTP endpoints. The backfill
        // catches up on the next run after re-enabling (loop starts at
        // start_date, firstOrCreate is idempotent).
        if (! config('roi.payments_enabled')) {
            $this->info('PAYMENTS_ENABLED is false — skipping pledge billing.');

            return self::SUCCESS;
        }

        $timezone = (string) config('roi.pledge_timezone');
        $today = now($timezone)->toDateString();
        $currentMonth = (string) PledgePayment::billingMonthFor(now());

        $created = 0;
        $missed = 0;
        $initiated = 0;

        // Card pledges keep their separate Paystack subscription flow (§14);
        // paused/cancelled/completed pledges owe nothing new (§17).
        Pledge::query()
            ->where('status', Pledge::STATUS_ACTIVE)
            ->where('method', Pledge::METHOD_MPESA)
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use ($pledges, $timezone, $currentMonth, $today, &$created, &$missed, &$initiated) {
                foreach ($chunk as $pledge) {
                    // §20: one bad pledge (corrupt row or gateway outage)
                    // must never abort the whole batch.
                    try {
                        $startMonth = $pledge->start_date?->format('Y-m') ?? $currentMonth;
                        $currentPayment = null;

                        $cursor = Carbon::createFromFormat(
                            'Y-m-01',
                            $startMonth.'-01',
                            $timezone,
                        );
                        while ($cursor->format('Y-m') <= $currentMonth) {
                            $month = $cursor->format('Y-m');
                            $payment = $pledges->ensureObligation(
                                $pledge,
                                $month,
                                $pledges->dueDateFor($pledge, $month)->toDateString(),
                            );

                            if ($payment->wasRecentlyCreated) {
                                $created++;
                            }

                            if ($month === $currentMonth) {
                                $currentPayment = $payment;
                            } elseif (in_array($payment->status, [PledgePayment::STATUS_DUE, PledgePayment::STATUS_FAILED], true)) {
                                // §13: an unpaid past month stays visible as
                                // MISSED — conditional so a concurrent
                                // charge.success settle is never downgraded.
                                $missed += PledgePayment::whereKey($payment->id)
                                    ->whereIn('status', [
                                        PledgePayment::STATUS_DUE,
                                        PledgePayment::STATUS_FAILED,
                                    ])
                                    ->update(['status' => PledgePayment::STATUS_MISSED]);
                            }

                            $cursor->addMonthNoOverflow();
                        }

                        if ($this->dueDayMatches($currentPayment, $today)) {
                            $pledges->initiateCharge($currentPayment);
                            $initiated++;
                        }
                    } catch (Throwable $e) {
                        // The attempt (if any) is recorded on the payment, so
                        // tomorrow's run sees attempts > 0 and does not spam.
                        report($e);
                        $this->warn("pledge {$pledge->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info(sprintf(
            'obligations created=%d, missed=%d, STK initiated=%d',
            $created,
            $missed,
            $initiated,
        ));

        return self::SUCCESS;
    }

    /** Due-day auto STK: current month, unpaid, never attempted yet (§11 step 1). */
    private function dueDayMatches(?PledgePayment $payment, string $today): bool
    {
        return $payment !== null
            && $payment->status === PledgePayment::STATUS_DUE
            && (int) $payment->attempts === 0
            && $payment->due_date?->format('Y-m-d') === $today;
    }
}
