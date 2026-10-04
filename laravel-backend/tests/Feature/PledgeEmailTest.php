<?php

namespace Tests\Feature;

use App\Mail\PledgeConfirmationMail;
use App\Mail\PledgeReminderMail;
use App\Models\PledgeEmailAttempt;
use App\Models\PledgePayment;
use App\Models\PledgePaymentAttempt;
use App\Services\EmailService;
use App\Services\PledgeTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Step 3 — supporter email (spec §11 reminder ledger, §6 pay links in email,
 * §20 HTML + plaintext, plan A2 confirmation, plan A4 channel shape, §22 no
 * real mail ever leaves a test: Mail::fake / mocked facade only).
 */
class PledgeEmailTest extends TestCase
{
    use RefreshDatabase;

    private const CHARGE_URL = 'https://api.paystack.co/charge';

    private const WEBHOOK_URL = '/api/payments/webhook/paystack';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'roi.payments_enabled' => true,
            'roi.paystack_secret_key' => 'sk_test_pledge',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            self::CHARGE_URL => Http::response([
                'status' => true,
                'message' => 'Authorization requested',
                'data' => ['reference' => null],
            ], 200),
        ]);
    }

    // ------------------------------------------------------------- helpers

    private function storePledge(): PledgePayment
    {
        $this->postJson('/api/pledges', [
            'name' => 'Monthly Supporter',
            'email' => 'supporter@test.example',
            'phone' => '0712345678',
            'amount' => 1000,
        ])->assertStatus(201);

        return PledgePayment::sole();
    }

    private function postWebhook(array $payload): mixed
    {
        $raw = json_encode($payload);

        return $this->call('POST', self::WEBHOOK_URL, [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, (string) config('roi.paystack_secret_key')),
        ], $raw);
    }

    private function settle(PledgePayment $payment, PledgePaymentAttempt $attempt): mixed
    {
        return $this->postWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => $attempt->reference,
                'amount' => (int) round(((float) $payment->amount_due) * 100),
                'currency' => $payment->currency,
                'id' => 900123,
            ],
        ])->assertOk();
    }

    // ------------------------------------------------------------ reminders

    public function test_reminder_email_renders_both_parts_with_secure_pay_link_and_ledger_row(): void
    {
        $payment = $this->storePledge();
        Mail::fake();

        $this->assertTrue(
            app(EmailService::class)->sendReminder($payment->fresh(), 'd-3')
        );

        Mail::assertSent(PledgeReminderMail::class, 1);

        $mailable = Mail::sent(PledgeReminderMail::class)->first();
        $this->assertTrue($mailable->hasTo('supporter@test.example'));

        // HTML part renders and embeds the pay link...
        $html = $mailable->render();
        $this->assertStringContainsString('/pledges/pay/', $html);
        $monthName = Carbon::createFromFormat('Y-m', $payment->billing_month)->format('F');
        $this->assertStringContainsString($monthName, $html); // month label
        $this->assertStringContainsString('KSh 1,000.00', $html);

        // ...plain-text part (§20) carries the same link...
        $text = view('emails.pledges.reminder-text', $mailable->buildViewData())->render();
        $this->assertStringContainsString('/pledges/pay/', $text);

        // ...and the embedded token actually opens the payment page (§6).
        $token = basename((string) parse_url($mailable->payUrl, PHP_URL_PATH));
        $this->assertNotNull(app(PledgeTokenService::class)->verify($token));

        $ledger = PledgeEmailAttempt::sole();
        $this->assertSame(PledgeEmailAttempt::KIND_REMINDER, $ledger->kind);
        $this->assertSame('d-3', $ledger->reminder_key);
        $this->assertSame(PledgeEmailAttempt::STATUS_SENT, $ledger->status);
        $this->assertNotNull($ledger->sent_at);
    }

    public function test_reminder_is_idempotent_per_key_and_skipped_once_paid(): void
    {
        $payment = $this->storePledge();
        $attempt = PledgePaymentAttempt::sole();
        Mail::fake();

        $svc = app(EmailService::class);
        $this->assertTrue($svc->sendReminder($payment->fresh(), 'd-3'));
        // Same key again (cron overlap) — refused, ledger-gated (§11).
        $this->assertFalse($svc->sendReminder($payment->fresh(), 'd-3'));

        // A different key is allowed (schedule: 1, 3, 7 days).
        $this->assertTrue($svc->sendReminder($payment->fresh(), 'd-7'));
        Mail::assertSent(PledgeReminderMail::class, 2);

        // Paid month: no reminders, for any key.
        $this->settle($payment, $attempt);
        $this->assertFalse($svc->sendReminder($payment->fresh(), 'd-1'));
        Mail::assertSent(PledgeReminderMail::class, 2);

        $this->assertSame(2, PledgeEmailAttempt::where('kind', PledgeEmailAttempt::KIND_REMINDER)->count());
    }

    // -------------------------------------------------------- confirmations

    public function test_confirmation_sent_exactly_once_even_when_webhook_replays(): void
    {
        $payment = $this->storePledge();
        $attempt = PledgePaymentAttempt::sole();
        Mail::fake();

        $this->settle($payment, $attempt);
        // Idempotent replay + a redundant direct call — both are no-ops.
        $this->settle($payment, $attempt);
        $this->assertFalse(app(EmailService::class)->sendConfirmation($payment->fresh()));

        Mail::assertSent(PledgeConfirmationMail::class, 1);
        Mail::assertNotSent(PledgeReminderMail::class);

        $mailable = Mail::sent(PledgeConfirmationMail::class)->first();
        $this->assertTrue($mailable->hasTo('supporter@test.example'));
        $this->assertStringContainsString('Payment received', (string) $mailable->envelope()->subject);

        // HTML + text parts both render.
        $this->assertStringContainsString('KSh 1,000.00', $mailable->render());
        $text = view('emails.pledges.confirmation-text', $mailable->buildViewData())->render();
        $this->assertStringContainsString('Thank you!', $text);
        $monthLabel = Carbon::createFromFormat('Y-m', $payment->billing_month)->format('F Y');
        $this->assertStringContainsString($monthLabel.' pledge', $text);

        $ledger = PledgeEmailAttempt::where('kind', PledgeEmailAttempt::KIND_CONFIRMATION)->sole();
        $this->assertSame(PledgeEmailAttempt::STATUS_SENT, $ledger->status);
        $this->assertSame($payment->id, $ledger->pledge_payment_id);
    }

    public function test_confirmation_not_sent_for_rejected_or_failed_settlement(): void
    {
        $payment = $this->storePledge();
        $attempt = PledgePaymentAttempt::sole();
        Mail::fake();

        // Amount mismatch (§9.4) — nothing settles, nothing confirms.
        $this->postWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => $attempt->reference,
                'amount' => 999999,
                'currency' => $payment->currency,
                'id' => 1,
            ],
        ])->assertOk();

        // charge.failed — nothing settles, nothing confirms.
        $this->postWebhook([
            'event' => 'charge.failed',
            'data' => ['reference' => $attempt->reference],
        ])->assertOk();

        Mail::assertNotSent(PledgeConfirmationMail::class);
        $this->assertSame(0, PledgeEmailAttempt::count());
    }

    // ------------------------------------------------------- channel/errors

    public function test_unsupported_channel_fails_soft_without_ledger_row(): void
    {
        $payment = $this->storePledge();
        Mail::fake();

        $result = app(EmailService::class)->send(
            'sms',
            EmailService::TEMPLATE_REMINDER,
            $payment,
            ['reminder_key' => 'd-3'],
        );

        $this->assertFalse($result);
        Mail::assertNothingSent();
        $this->assertSame(0, PledgeEmailAttempt::count());
    }

    public function test_mail_failure_is_recorded_on_ledger_and_never_throws(): void
    {
        $payment = $this->storePledge();

        // Simulate an SMTP outage: the mailer itself blows up (§22 — no real send).
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

        $result = app(EmailService::class)->sendReminder($payment->fresh(), 'd-3');

        $this->assertFalse($result);
        $ledger = PledgeEmailAttempt::sole();
        $this->assertSame(PledgeEmailAttempt::STATUS_FAILED, $ledger->status);
        $this->assertStringContainsString('SMTP down', (string) $ledger->error);
    }

    public function test_failed_send_is_retried_on_the_same_ledger_row(): void
    {
        $payment = $this->storePledge();

        // First run: SMTP outage — recorded as failed on the ledger.
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));
        $this->assertFalse(app(EmailService::class)->sendReminder($payment->fresh(), 'd-3'));

        $ledger = PledgeEmailAttempt::sole();
        $this->assertSame(PledgeEmailAttempt::STATUS_FAILED, $ledger->status);

        // Next cron run, same key: the mailer recovers — the SAME row must
        // flip to sent (no second row, no skipped retry).
        Mail::shouldReceive('to')->once()->andReturnUsing(fn () => new class
        {
            public function send($mailable): bool
            {
                return true;
            }
        });

        $this->assertTrue(app(EmailService::class)->sendReminder($payment->fresh(), 'd-3'));

        $ledger->refresh();
        $this->assertSame(PledgeEmailAttempt::STATUS_SENT, $ledger->status);
        $this->assertNull($ledger->error);
        $this->assertNotNull($ledger->sent_at);
        $this->assertSame(1, PledgeEmailAttempt::count());
    }

    public function test_settlement_webhook_still_acknowledges_when_confirmation_mail_fails(): void
    {
        $payment = $this->storePledge();
        $attempt = PledgePaymentAttempt::sole();

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));

        // Money settled — the webhook must still return 200 (spec §9) even
        // though the confirmation email could not go out.
        $this->settle($payment, $attempt)->assertOk();

        $payment->refresh();
        $this->assertSame(PledgePayment::STATUS_PAID, $payment->status);

        $ledger = PledgeEmailAttempt::where('kind', PledgeEmailAttempt::KIND_CONFIRMATION)->sole();
        $this->assertSame(PledgeEmailAttempt::STATUS_FAILED, $ledger->status);
        $this->assertStringContainsString('SMTP down', (string) $ledger->error);
    }
}
