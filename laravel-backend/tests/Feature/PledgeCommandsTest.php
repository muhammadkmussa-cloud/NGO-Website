<?php

namespace Tests\Feature;

use App\Mail\PledgeReminderMail;
use App\Models\AuditLog;
use App\Models\Pledge;
use App\Models\PledgeEmailAttempt;
use App\Models\PledgePayment;
use App\Models\PledgePaymentAttempt;
use App\Services\PledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Step 4 — scheduler commands (spec §11 reminder schedule, §12 anchor
 * advancement, §13 missed months stay visible, §19 pledge timezone, §22 tests
 * #1/#6/#12/#19: obligation generation, reminder email, duplicate scheduler
 * execution, next-month obligation).
 *
 * No test ever reaches a real gateway or a real SMTP server (Http::fake +
 * Mail::fake).
 */
class PledgeCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const CHARGE_URL = 'https://api.paystack.co/charge';

    /** @var array<int, \Throwable|Response> FIFO charge outcomes. */
    private array $chargeScript = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'roi.payments_enabled' => true,
            'roi.paystack_secret_key' => 'sk_test_pledge',
        ]);

        // Freeze the clock (spec §19): without it the anchor-day assertions
        // would break on Jan 31 / Aug 31, where subMonthsNoOverflow clamps
        // the start day to 30 and the due-day STK legitimately does not fire.
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'Africa/Nairobi'));

        Http::preventStrayRequests();
        Http::fake([
            self::CHARGE_URL => function () {
                if ($this->chargeScript !== []) {
                    $next = array_shift($this->chargeScript);
                    if ($next instanceof \Throwable) {
                        throw $next;
                    }

                    return $next;
                }

                return Http::response([
                    'status' => true,
                    'message' => 'Authorization requested',
                    'data' => ['reference' => null],
                ], 200);
            },
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- helpers

    private function makePledge(array $overrides = []): Pledge
    {
        $tz = (string) config('roi.pledge_timezone');
        $today = now($tz)->toDateString();

        return Pledge::create(array_merge([
            'name' => 'Monthly Supporter',
            'email' => 'supporter@test.example',
            'phone' => '254712345678',
            'amount' => 1000,
            'currency' => 'KES',
            'method' => Pledge::METHOD_MPESA,
            'channel' => 'mobile_money',
            'status' => Pledge::STATUS_ACTIVE,
            'start_date' => $today,
            'next_payment_date' => $today,
        ], $overrides));
    }

    /** Builds one obligation for a pledge, due N days ago. */
    private function duePayment(
        int $daysAgo,
        string $status = PledgePayment::STATUS_DUE,
        array $pledgeOverrides = [],
        array $paymentOverrides = [],
    ): PledgePayment {
        $tz = (string) config('roi.pledge_timezone');
        $pledge = $this->makePledge($pledgeOverrides);

        return PledgePayment::create(array_merge([
            'pledge_id' => $pledge->id,
            'billing_month' => Carbon::now($tz)->format('Y-m'),
            'amount_due' => 1000,
            'currency' => 'KES',
            'due_date' => Carbon::now($tz)->subDays($daysAgo)->toDateString(),
            'status' => $status,
            'method' => $pledge->method,
            'channel' => 'mobile_money',
            'attempts' => 0,
        ], $paymentOverrides));
    }

    // ------------------------------------------------------------ roi:pledges-bill

    public function test_bill_backfills_months_marks_past_unpaid_as_missed_and_starts_due_day_stk(): void
    {
        $tz = (string) config('roi.pledge_timezone');
        $start = now($tz)->subMonthsNoOverflow(2)->startOfDay();

        $pledge = $this->makePledge([
            'start_date' => $start->toDateString(),
            'next_payment_date' => $start->toDateString(),
        ]);

        $this->artisan('roi:pledges-bill')->assertSuccessful();

        // Spec §13: one obligation per month from start to now.
        $this->assertSame(3, PledgePayment::where('pledge_id', $pledge->id)->count());

        // Past unpaid months stay visible as MISSED (never merged away).
        $this->assertSame(
            2,
            PledgePayment::where('pledge_id', $pledge->id)
                ->where('status', PledgePayment::STATUS_MISSED)
                ->count(),
        );

        // Current month is due today (anchor day) and the initial STK fired.
        $current = PledgePayment::where('pledge_id', $pledge->id)
            ->where('billing_month', now($tz)->format('Y-m'))
            ->sole();
        $this->assertSame(now($tz)->toDateString(), $current->due_date->format('Y-m-d'));
        $this->assertSame(PledgePayment::STATUS_PENDING, $current->status);
        $this->assertSame(1, $current->attempts);
        Http::assertSentCount(1);

        // Spec §22 #12: duplicate scheduler execution changes nothing.
        $this->artisan('roi:pledges-bill')->assertSuccessful();
        $this->assertSame(3, PledgePayment::where('pledge_id', $pledge->id)->count());
        $this->assertSame(
            2,
            PledgePayment::where('pledge_id', $pledge->id)
                ->where('status', PledgePayment::STATUS_MISSED)
                ->count(),
        );
        Http::assertSentCount(1);
        $this->assertSame(1, PledgePaymentAttempt::count());
    }

    public function test_bill_next_month_obligation_uses_anchor_day_clamped_to_month_length(): void
    {
        $tz = (string) config('roi.pledge_timezone');

        // Anchor on the 31st: February has 28 days, so the obligation clamps.
        $pledge = $this->makePledge([
            'start_date' => '2026-01-31',
            'next_payment_date' => '2026-01-31',
        ]);

        $this->artisan('roi:pledges-bill')->assertSuccessful();

        // Spec §22 #19: every month from start to current exists, each on the
        // anchor day clamped to that month's length.
        $payments = PledgePayment::where('pledge_id', $pledge->id)
            ->orderBy('billing_month')
            ->get();

        $expectedMonths = [];
        $cursor = Carbon::create(2026, 1, 1, 0, 0, 0, $tz);
        $now = Carbon::now($tz);
        while ($cursor->format('Y-m') <= $now->format('Y-m')) {
            $expectedMonths[$cursor->format('Y-m')] = min(31, $cursor->daysInMonth());
            $cursor->addMonthNoOverflow();
        }

        $this->assertSame(array_keys($expectedMonths), $payments->pluck('billing_month')->all());
        foreach ($payments as $payment) {
            $this->assertSame(
                sprintf('%s-%02d', $payment->billing_month, $expectedMonths[$payment->billing_month]),
                $payment->due_date->format('Y-m-d'),
            );
        }
    }

    public function test_bill_skips_non_active_non_mpesa_and_pledged_card_pledges(): void
    {
        $tz = (string) config('roi.pledge_timezone');
        $start = now($tz)->subMonthsNoOverflow(2)->toDateString();

        $this->makePledge(['status' => Pledge::STATUS_CANCELLED, 'start_date' => $start]);
        $this->makePledge(['status' => Pledge::STATUS_PAUSED, 'start_date' => $start]);
        $this->makePledge(['method' => Pledge::METHOD_CARD, 'start_date' => $start]);

        $this->artisan('roi:pledges-bill')->assertSuccessful();

        // §14: card pledges keep their own Paystack subscription flow.
        // §17: cancelled/paused pledges owe nothing new.
        $this->assertSame(0, PledgePayment::count());
        Http::assertNothingSent();
    }

    public function test_bill_gateway_failure_keeps_run_alive_and_never_spams_stk(): void
    {
        $tz = (string) config('roi.pledge_timezone');
        $start = now($tz)->toDateString();
        $pledge = $this->makePledge(['start_date' => $start, 'next_payment_date' => $start]);

        $this->chargeScript[] = Http::response(['status' => false, 'message' => 'Service busy'], 500);

        // One pledge's gateway failure must not abort the batch (§20).
        $this->artisan('roi:pledges-bill')->assertSuccessful();

        $payment = PledgePayment::where('pledge_id', $pledge->id)->sole();
        $this->assertSame(PledgePayment::STATUS_DUE, $payment->status);
        $this->assertSame(1, $payment->attempts);

        // Spec §22 #12: the next run sees attempts > 0 and does not re-STK.
        $this->artisan('roi:pledges-bill')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(1, PledgePaymentAttempt::count());
        $this->assertSame(PledgePaymentAttempt::STATUS_FAILED, PledgePaymentAttempt::sole()->status);
    }

    // ----------------------------------------------------------- roi:pledges-remind

    public function test_remind_sends_each_configured_offset_once_and_skips_ineligible(): void
    {
        Mail::fake();
        $tz = (string) config('roi.pledge_timezone');

        // Eligible: unpaid, due exactly offset days ago, active M-Pesa pledge.
        $this->duePayment(1);
        $this->duePayment(3, PledgePayment::STATUS_FAILED);
        $this->duePayment(7, PledgePayment::STATUS_MISSED);

        // Ineligible set (spec §11 "only if not already paid" + §14/§17).
        $this->duePayment(3, PledgePayment::STATUS_PAID);                        // already paid
        $this->duePayment(3, PledgePayment::STATUS_DUE, ['status' => Pledge::STATUS_CANCELLED]); // cancelled
        $this->duePayment(3, PledgePayment::STATUS_DUE, ['status' => Pledge::STATUS_PAUSED]);    // paused
        $this->duePayment(3, PledgePayment::STATUS_PENDING);                     // STK still in flight
        $this->duePayment(3, PledgePayment::STATUS_DUE, ['method' => Pledge::METHOD_CARD]); // card flow
        $this->duePayment(2);                                                    // offset 2 not configured

        $this->artisan('roi:pledges-remind')->assertSuccessful();

        Mail::assertSent(PledgeReminderMail::class, 3);
        $this->assertSame(3, PledgeEmailAttempt::query()
            ->where('kind', PledgeEmailAttempt::KIND_REMINDER)
            ->where('status', PledgeEmailAttempt::STATUS_SENT)
            ->count());

        // Reminders never touch the payment gateway (§11 is email-only).
        Http::assertNothingSent();

        // Spec §22 #6/#12: duplicate scheduler execution never double-sends.
        $this->artisan('roi:pledges-remind')->assertSuccessful();
        Mail::assertSent(PledgeReminderMail::class, 3);
        $this->assertSame(3, PledgeEmailAttempt::count());
    }

    public function test_remind_expires_stale_pending_prompts_before_reminding(): void
    {
        Mail::fake();

        $payment = $this->duePayment(1, PledgePayment::STATUS_PENDING, [], [
            'last_attempt_at' => now()->subMinutes(20),
        ]);
        $attempt = PledgePaymentAttempt::create([
            'pledge_payment_id' => $payment->id,
            'pledge_id' => $payment->pledge_id,
            'reference' => 'DEMO-PLE-'.str_repeat('a', 32),
            'amount' => 1000,
            'currency' => 'KES',
            'method' => Pledge::METHOD_MPESA,
            'channel' => 'mobile_money',
            'status' => PledgePaymentAttempt::STATUS_INITIATED,
            'initiated_at' => now()->subMinutes(20),
        ]);
        // created_at is guarded from mass-assignment — age it directly.
        PledgePaymentAttempt::whereKey($attempt->id)
            ->update(['created_at' => now()->subMinutes(20)]);

        $this->artisan('roi:pledges-remind')->assertSuccessful();

        // §8/§21: abandoned prompt retired (month DUE again) + reminder out.
        $this->assertSame(PledgePaymentAttempt::STATUS_EXPIRED, $attempt->fresh()->status);
        $this->assertSame(PledgePayment::STATUS_DUE, $payment->fresh()->status);
        Mail::assertSent(PledgeReminderMail::class, 1);
        $this->assertSame(1, PledgeEmailAttempt::count());
    }

    public function test_remind_respects_batch_size_per_offset(): void
    {
        Mail::fake();
        config(['roi.pledge_email_batch_size' => 2]);

        $this->duePayment(1);
        $this->duePayment(1);
        $this->duePayment(1);

        $this->artisan('roi:pledges-remind')->assertSuccessful();
        Mail::assertSent(PledgeReminderMail::class, 2);
        $this->assertSame(2, PledgeEmailAttempt::count());

        // Next hourly run picks up the remainder (ledger gate, not a skip).
        $this->artisan('roi:pledges-remind')->assertSuccessful();
        Mail::assertSent(PledgeReminderMail::class, 3);
        $this->assertSame(3, PledgeEmailAttempt::count());
    }

    public function test_remind_counts_and_audit_trail_update_on_each_send(): void
    {
        Mail::fake();

        $payment = $this->duePayment(1);

        $this->artisan('roi:pledges-remind')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(1, $payment->reminder_count);
        $this->assertNotNull($payment->last_reminder_at);

        $this->assertSame(
            1,
            AuditLog::where('action', 'Pledge reminder email sent')->count(),
        );
    }

    public function test_payment_kill_switch_freezes_bill_and_remind(): void
    {
        // Due-day pledge: the bill cron would normally create its obligation
        // AND dispatch an STK prompt right now.
        $this->makePledge();

        config(['roi.payments_enabled' => false]);

        $this->artisan('roi:pledges-bill')->assertSuccessful();
        $this->assertSame(0, PledgePayment::count());
        $this->assertSame(0, PledgePaymentAttempt::count());
        Http::assertNothingSent();

        // An eligible unpaid month exists — reminders must still not send.
        Mail::fake();
        $this->duePayment(1);
        $this->artisan('roi:pledges-remind')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, PledgeEmailAttempt::count());
        $this->assertSame(
            0,
            AuditLog::where('action', 'Pledge reminder email sent')->count(),
        );
    }

    // ------------------------------------------------- abandoned-prompt sweeper

    public function test_sweeper_retires_only_stale_initiated_attempts_and_never_downgrades_paid(): void
    {
        $service = app(PledgeService::class);

        // A settled month whose SUCCEEDED attempt is old must survive untouched.
        $paid = $this->duePayment(1, PledgePayment::STATUS_PAID, [], [
            'attempts' => 1,
            'paid_at' => now()->subMinutes(40),
            'last_attempt_at' => now()->subMinutes(40),
        ]);
        $succeeded = PledgePaymentAttempt::create([
            'pledge_payment_id' => $paid->id,
            'pledge_id' => $paid->pledge_id,
            'reference' => 'DEMO-PLE-'.str_repeat('b', 32),
            'amount' => 1000,
            'currency' => 'KES',
            'method' => Pledge::METHOD_MPESA,
            'channel' => 'mobile_money',
            'status' => PledgePaymentAttempt::STATUS_SUCCEEDED,
            'initiated_at' => now()->subMinutes(40),
        ]);
        PledgePaymentAttempt::whereKey($succeeded->id)->update(['created_at' => now()->subMinutes(40)]);

        // A genuinely abandoned prompt: stale PENDING month + stale INITIATED attempt.
        $stale = $this->duePayment(1, PledgePayment::STATUS_PENDING, [], [
            'attempts' => 1,
            'last_attempt_at' => now()->subMinutes(40),
        ]);
        $initiated = PledgePaymentAttempt::create([
            'pledge_payment_id' => $stale->id,
            'pledge_id' => $stale->pledge_id,
            'reference' => 'DEMO-PLE-'.str_repeat('c', 32),
            'amount' => 1000,
            'currency' => 'KES',
            'method' => Pledge::METHOD_MPESA,
            'channel' => 'mobile_money',
            'status' => PledgePaymentAttempt::STATUS_INITIATED,
            'initiated_at' => now()->subMinutes(40),
        ]);
        PledgePaymentAttempt::whereKey($initiated->id)->update(['created_at' => now()->subMinutes(40)]);

        $this->assertSame(1, $service->expireAbandonedAttempts());

        // §4/§21: the abandoned prompt is retired and the month becomes retryable...
        $this->assertSame(PledgePaymentAttempt::STATUS_EXPIRED, $initiated->fresh()->status);
        $this->assertSame(PledgePayment::STATUS_DUE, $stale->fresh()->status);
        $this->assertNull($stale->fresh()->paystack_reference);

        // ...while the settled month is never downgraded and its attempt is preserved.
        $this->assertSame(PledgePayment::STATUS_PAID, $paid->fresh()->status);
        $this->assertSame(PledgePaymentAttempt::STATUS_SUCCEEDED, $succeeded->fresh()->status);

        // Idempotent: a second sweep has nothing left to retire.
        $this->assertSame(0, $service->expireAbandonedAttempts());
        $this->assertSame(PledgePayment::STATUS_PAID, $paid->fresh()->status);
    }

    // --------------------------------------- reminders stop once a month is paid

    public function test_reminders_stop_immediately_once_the_month_is_paid(): void
    {
        Mail::fake();

        // due_date = 1 day ago → the d-1 offset fires now.
        $payment = $this->duePayment(1);

        $this->artisan('roi:pledges-remind')->assertSuccessful();
        Mail::assertSent(PledgeReminderMail::class, 1);
        $this->assertSame(1, $payment->fresh()->reminder_count);

        // The month settles between reminder offsets (e.g. via the secure link).
        $payment->update(['status' => PledgePayment::STATUS_PAID, 'paid_at' => now()]);

        // Advance into the d-3 window, then the d-7 window — neither may send.
        $this->travel(2)->days();
        $this->artisan('roi:pledges-remind')->assertSuccessful();

        $this->travel(4)->days();
        $this->artisan('roi:pledges-remind')->assertSuccessful();

        Mail::assertSent(PledgeReminderMail::class, 1); // the single d-1 reminder
        $this->assertSame(
            1,
            PledgeEmailAttempt::where('kind', PledgeEmailAttempt::KIND_REMINDER)
                ->where('status', PledgeEmailAttempt::STATUS_SENT)
                ->count(),
        );
        $this->assertSame(1, $payment->fresh()->reminder_count);
    }
}
