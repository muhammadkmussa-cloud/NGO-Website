<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Pledge;
use App\Models\PledgePayment;
use App\Models\PledgePaymentAttempt;
use App\Services\Exceptions\PaymentGatewayException;
use App\Services\PledgeService;
use App\Services\PledgeTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Step 6 — spec §17 supporter cancellation + §21 audit trail, plus the two
 * §22 scenarios only these cover end-to-end (#17 cancellation, #19 no future
 * obligations after cancel). No test ever reaches a real gateway: Paystack
 * /charge is Http::fake'd and webhooks are posted with a locally computed
 * HMAC (spec §22).
 */
class PledgeCancellationAuditTest extends TestCase
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

        // Frozen clock (mirrors PledgeCommandsTest): due dates, the 60s STK
        // cooldown and reminder offsets all become deterministic.
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'Africa/Nairobi'));

        Http::preventStrayRequests();
        Http::fake([
            self::CHARGE_URL => Http::response([
                'status' => true,
                'message' => 'Authorization requested',
                'data' => ['reference' => null],
            ], 200),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- helpers

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Monthly Supporter',
            'email' => 'supporter@test.example',
            'phone' => '0712345678',
            'amount' => 1000,
        ], $overrides);
    }

    private function postWebhook(array $payload): mixed
    {
        $raw = json_encode($payload);
        $signature = hash_hmac('sha512', $raw, (string) config('roi.paystack_secret_key'));

        return $this->call('POST', self::WEBHOOK_URL, [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $raw);
    }

    /** Creates a pledge (one obligation + one in-flight attempt) and returns the month. */
    private function createStore(): PledgePayment
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);

        return PledgePayment::sole();
    }

    private function tokenFor(PledgePayment $payment): string
    {
        return app(PledgeTokenService::class)->mint($payment);
    }

    private function audits(string $action)
    {
        return AuditLog::where('action', $action);
    }

    // ------------------------------------------------------------ §17 cancel

    public function test_supporter_cancels_via_secure_link_and_history_survives(): void
    {
        $payment = $this->createStore();
        $pledge = $payment->pledge;
        $token = $this->tokenFor($payment);
        $attemptsBefore = PledgePaymentAttempt::count();

        $this->postJson('/api/pledges/pay/'.$token.'/cancel')
            ->assertOk()
            ->assertJsonPath('status', Pledge::STATUS_CANCELLED);

        $this->assertSame(Pledge::STATUS_CANCELLED, $pledge->refresh()->status);

        // Historical rows untouched: the month, its status and its attempts.
        $this->assertSame(1, PledgePayment::where('pledge_id', $pledge->id)->count());
        $this->assertSame(PledgePayment::STATUS_PENDING, $payment->refresh()->status);
        $this->assertSame($attemptsBefore, PledgePaymentAttempt::count());

        $this->assertSame(1, $this->audits('Pledge cancelled')->count());

        // No new charge may start (spec §17: "do not send future STK requests")
        // — service gate...
        try {
            app(PledgeService::class)->initiateCharge($payment);
            $this->fail('initiateCharge must refuse a cancelled pledge');
        } catch (PaymentGatewayException $e) {
            $this->assertSame(409, $e->statusCode);
        }
        $this->assertSame($attemptsBefore, PledgePaymentAttempt::count());

        // ...and the pay endpoint behind the secure link.
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])
            ->assertStatus(409)
            ->assertJsonPath('detail', 'This pledge is no longer active.');
        $this->assertSame($attemptsBefore, PledgePaymentAttempt::count());
    }

    public function test_cancel_is_idempotent_and_audited_once(): void
    {
        $payment = $this->createStore();
        $token = $this->tokenFor($payment);

        $this->postJson('/api/pledges/pay/'.$token.'/cancel')->assertOk();
        $second = $this->postJson('/api/pledges/pay/'.$token.'/cancel')
            ->assertOk()
            ->assertJsonPath('status', Pledge::STATUS_CANCELLED);

        $this->assertNotEmpty($second->json('message'));
        $this->assertSame(Pledge::STATUS_CANCELLED, $payment->pledge->refresh()->status);
        $this->assertSame(1, $this->audits('Pledge cancelled')->count());
    }

    public function test_cancel_rejects_invalid_expired_and_tampered_tokens(): void
    {
        $payment = $this->createStore();

        $this->postJson('/api/pledges/pay/not-a-valid-token/cancel')
            ->assertStatus(404);

        $valid = $this->tokenFor($payment);
        [$body, $sig] = explode('.', $valid, 2);
        $tampered = $body.'.'.strrev($sig);
        $this->postJson('/api/pledges/pay/'.$tampered.'/cancel')->assertStatus(404);

        // Expired (link TTL is 168h): freeze, mint, jump past the deadline.
        $this->travel(8)->days();
        $this->postJson('/api/pledges/pay/'.$valid.'/cancel')->assertStatus(404);

        // Nothing above may have cancelled anything.
        $this->assertSame(Pledge::STATUS_ACTIVE, $payment->pledge->refresh()->status);
        $this->assertSame(0, $this->audits('Pledge cancelled')->count());
    }

    public function test_cancelled_pledge_gets_no_new_obligations_and_no_reminders(): void
    {
        $payment = $this->createStore();
        $this->postJson('/api/pledges/pay/'.$this->tokenFor($payment).'/cancel')->assertOk();

        // Next month: the bill cron must not create anything for it.
        $this->travelTo(Carbon::parse('2026-11-05 09:00:00', 'Africa/Nairobi'));
        $this->artisan('roi:pledges-bill')->assertSuccessful();
        $this->assertSame(1, PledgePayment::where('pledge_id', $payment->pledge_id)->count());

        // All reminder offsets are now past due — still nothing may be sent.
        // (The remind cron first sweeps the stale prompt back to DUE, proving
        // the skip comes from the cancelled pledge gate, not the status gate.)
        Mail::fake();
        $this->artisan('roi:pledges-remind')->assertSuccessful();

        $this->assertSame(PledgePayment::STATUS_DUE, $payment->refresh()->status);
        Mail::assertNothingSent();
        $this->assertSame(0, $this->audits('Pledge reminder email sent')->count());
    }

    public function test_webhook_still_settles_paid_month_after_cancellation(): void
    {
        $payment = $this->createStore();
        $attempt = PledgePaymentAttempt::latest('id')->firstOrFail();

        $this->postJson('/api/pledges/pay/'.$this->tokenFor($payment).'/cancel')->assertOk();

        // Money already in flight must be recorded even though the pledge
        // is cancelled (spec §17 stops FUTURE months, never history).
        $this->postWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => $attempt->reference,
                'amount' => (int) round(((float) $payment->amount_due) * 100),
                'currency' => $payment->currency,
                'id' => 900777,
            ],
        ])->assertOk();

        $this->assertSame(PledgePayment::STATUS_PAID, $payment->refresh()->status);
        $this->assertSame(Pledge::STATUS_CANCELLED, $payment->pledge->refresh()->status);
        $this->assertSame(1, $this->audits('Pledge payment settled')->count());
    }

    // ------------------------------------------------------------- §21 audit

    public function test_audit_trail_covers_obligation_initiated_link_used_and_failed(): void
    {
        $payment = $this->createStore();

        $this->assertSame(1, $this->audits('Monthly obligation created')->count());
        $this->assertSame(1, $this->audits('Payment initiated')->count());

        // A double-click inside the cooldown reuses the prompt — no second
        // "initiated" row (§18 anti-spam mirrors the audit trail).
        $token = $this->tokenFor($payment);
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])
            ->assertStatus(202);
        $this->assertSame(1, $this->audits('Payment initiated')->count());

        // Opening the secure link is auditable (§21 "payment link used").
        $this->get('/pledges/pay/'.$token)->assertOk();
        $this->assertSame(1, $this->audits('Payment link used')->count());

        // A failed webhook marks the month and audits exactly one failure.
        $this->postWebhook([
            'event' => 'charge.failed',
            'data' => ['reference' => $payment->fresh()->paystack_reference],
        ])->assertOk();
        $this->assertSame(PledgePayment::STATUS_FAILED, $payment->refresh()->status);
        $this->assertSame(1, $this->audits('Payment failed')->count());

        // Replay of the same failure must not duplicate the audit row.
        $this->postWebhook([
            'event' => 'charge.failed',
            'data' => ['reference' => $payment->paystack_reference],
        ])->assertOk();
        $this->assertSame(1, $this->audits('Payment failed')->count());

        // §21: never log sensitive credentials.
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString('sk_test', (string) $log->details);
            $this->assertStringNotContainsString('secret', (string) $log->details);
        }
    }
}
