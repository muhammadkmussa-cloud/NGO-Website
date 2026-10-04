<?php

namespace Tests\Feature;

use App\Models\Pledge;
use App\Models\PledgePayment;
use App\Models\PledgePaymentAttempt;
use App\Services\PledgeService;
use App\Services\PledgeTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step 2 — pledge collection flow (spec §2 create, §6–§8 secure links + STK
 * retry, §9 idempotent webhook settlement, §10 server-side status, §12 anchor
 * advancement, §18 double-click/retry protection).
 *
 * No test ever reaches a real gateway: Paystack /charge is Http::fake'd and
 * webhooks are posted with a locally computed HMAC (spec §22).
 */
class PledgeFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CHARGE_URL = 'https://api.paystack.co/charge';

    private const WEBHOOK_URL = '/api/payments/webhook/paystack';

    /**
     * Canned /charge outcomes consumed FIFO by the fake below: push a
     * Response (e.g. Http::response([...], 500)) or a Throwable (e.g. a
     * ConnectionException) to script gateway failures. Empty => success.
     *
     * @var array<int, \Throwable|Response>
     */
    private array $chargeScript = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'roi.payments_enabled' => true,
            'roi.paystack_secret_key' => 'sk_test_pledge',
        ]);

        // Spec §22: tests must NEVER reach a real gateway — anything the fake
        // does not explicitly answer throws instead of connecting.
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

    private function latestAttempt(): PledgePaymentAttempt
    {
        return PledgePaymentAttempt::latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------- §2 create

    public function test_store_creates_pledge_obligation_and_dispatches_stk(): void
    {
        $response = $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);

        $response->assertJsonPath('pledge.method', 'mpesa')
            ->assertJsonPath('pledge.currency', 'KES')
            ->assertJsonPath('payment.status', 'PENDING')
            ->assertJsonPath('payment.billing_month', PledgePayment::billingMonthFor(now()));

        $pledge = Pledge::sole();
        $this->assertSame('254712345678', $pledge->phone);
        $this->assertSame(Pledge::STATUS_ACTIVE, $pledge->status);
        // First obligation lands on the Nairobi current month (spec §19).
        $this->assertSame(
            now(config('roi.pledge_timezone'))->format('Y-m'),
            $pledge->payments()->sole()->billing_month,
        );

        $attempt = $this->latestAttempt();
        $this->assertSame(PledgePaymentAttempt::STATUS_INITIATED, $attempt->status);
        $this->assertSame(PledgePayment::STATUS_PENDING, $attempt->pledgePayment->status);

        // The gateway request carries the mpesa provider payload (spec §5).
        Http::assertSent(function ($request) {
            return $request->url() === self::CHARGE_URL
                && $request['mobile_money']['phone'] === '+254712345678'
                && $request['mobile_money']['provider'] === 'mpesa'
                && str_starts_with((string) $request['reference'], 'DEMO-PLE-')
                && $request['currency'] === 'KES'
                && $request['amount'] === 100000;
        });
        Http::assertSentCount(1);
    }

    public function test_store_reuses_recent_duplicate_and_does_not_double_prompt(): void
    {
        $payload = $this->storePayload();
        $first = $this->postJson('/api/pledges', $payload)->assertStatus(201);
        $second = $this->postJson('/api/pledges', $payload)->assertStatus(200);

        $this->assertSame($first->json('pledge.id'), $second->json('pledge.id'));
        $this->assertSame(1, Pledge::count());
        $this->assertSame(1, PledgePayment::count());
        // Cooldown + duplicate reuse: exactly one STK prompt (spec §18).
        Http::assertSentCount(1);
    }

    public function test_store_rejects_invalid_phone(): void
    {
        $this->postJson('/api/pledges', $this->storePayload(['phone' => 'abc']))
            ->assertStatus(400)
            ->assertJsonPath('detail', 'Invalid M-Pesa phone number format. Please enter a valid Kenyan mobile number (e.g., 0712345678 or 254712345678).');

        $this->assertSame(0, Pledge::count());
        $this->assertSame(0, PledgePaymentAttempt::count());
        Http::assertNothingSent();
    }

    public function test_store_validates_amount_bounds(): void
    {
        // API validation errors use the app's FastAPI-parity wire format:
        // {"detail": [{loc: ["body", field], msg, type}, ...]} (bootstrap/app.php).
        $over = $this->postJson('/api/pledges', $this->storePayload(['amount' => 150001]))
            ->assertStatus(422);
        $this->assertTrue(
            in_array(['body', 'amount'], array_column($over->json('detail') ?? [], 'loc'), true),
        );

        $zero = $this->postJson('/api/pledges', $this->storePayload(['amount' => 0]))
            ->assertStatus(422);
        $this->assertTrue(
            in_array(['body', 'amount'], array_column($zero->json('detail') ?? [], 'loc'), true),
        );

        $this->assertSame(0, Pledge::count());
        Http::assertNothingSent();
    }

    public function test_store_returns_503_when_payments_disabled(): void
    {
        config(['roi.payments_enabled' => false]);

        $this->postJson('/api/pledges', $this->storePayload())
            ->assertStatus(503);

        $this->assertSame(0, Pledge::count());
        Http::assertNothingSent();
    }

    // -------------------------------------------------- §9 webhook settlement

    public function test_charge_success_webhook_settles_advances_schedule_and_replays_safely(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $attempt = $this->latestAttempt();
        $payment = $attempt->pledgePayment;
        $pledge = $attempt->pledge;

        $raw = [
            'event' => 'charge.success',
            'data' => [
                'reference' => $attempt->reference,
                'amount' => (int) round(((float) $payment->amount_due) * 100),
                'currency' => $payment->currency,
                'id' => 900123,
            ],
        ];

        $this->postWebhook($raw)->assertOk();

        $payment->refresh();
        $this->assertSame(PledgePayment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('900123', $payment->paystack_transaction_id);
        $this->assertSame(PledgePaymentAttempt::STATUS_SUCCEEDED, $attempt->refresh()->status);

        // Spec §12: anchor day-of-month advanced one month, never shrunk.
        $expectedNext = app(PledgeService::class)
            ->nextAnchorAfter($pledge, $payment->billing_month)
            ->toDateString();
        $this->assertSame($expectedNext, $pledge->refresh()->next_payment_date->format('Y-m-d'));
        $this->assertSame(
            now(config('roi.pledge_timezone'))->toDateString(),
            $pledge->last_successful_payment_date->format('Y-m-d'),
        );
        $this->assertSame(1, PledgePayment::where('status', PledgePayment::STATUS_PAID)->count());

        // Idempotent replay (spec §9): second delivery changes nothing.
        $this->postWebhook($raw)->assertOk();
        $this->assertSame(1, PledgePayment::where('status', PledgePayment::STATUS_PAID)->count());
        $this->assertSame(1, PledgePaymentAttempt::where('status', PledgePaymentAttempt::STATUS_SUCCEEDED)->count());
    }

    public function test_charge_success_webhook_rejects_amount_mismatch(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $attempt = $this->latestAttempt();

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => $attempt->reference,
                'amount' => 999,
                'currency' => 'KES',
            ],
        ])->assertOk();

        $this->assertSame(PledgePayment::STATUS_PENDING, $attempt->pledgePayment->status);
        $this->assertSame(PledgePaymentAttempt::STATUS_INITIATED, $attempt->refresh()->status);
    }

    public function test_charge_failed_webhook_marks_attempt_and_payment_failed_then_allows_retry(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $attempt = $this->latestAttempt();

        $this->postWebhook([
            'event' => 'charge.failed',
            'data' => ['reference' => $attempt->reference],
        ])->assertOk();

        $this->assertSame(PledgePaymentAttempt::STATUS_FAILED, $attempt->refresh()->status);
        $this->assertSame(PledgePayment::STATUS_FAILED, $attempt->pledgePayment->status);

        // Spec §4: the month stays outstanding — a later retry can start fresh.
        $token = app(PledgeTokenService::class)->mint($attempt->pledgePayment);
        $this->travel(PledgeService::ATTEMPT_COOLDOWN_SECONDS + 1)->seconds();
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])
            ->assertStatus(202);

        $this->assertSame(2, PledgePaymentAttempt::count());
        $this->assertSame(PledgePayment::STATUS_PENDING, $attempt->pledgePayment->fresh()->status);
    }

    public function test_unknown_pledge_reference_webhook_is_acknowledged_without_settling(): void
    {
        $this->postWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'DEMO-PLE-'.strtoupper(str_repeat('ab', 16)),
                'amount' => 100000,
                'currency' => 'KES',
            ],
        ])->assertOk();

        $this->assertSame(0, PledgePayment::count());
    }

    public function test_non_pledge_references_keep_following_existing_donation_path(): void
    {
        // A non-DEMO-PLE reference must not enter pledge settlement.
        $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => 'DEMO-DON-'.strtoupper(str_repeat('cd', 16)), 'amount' => 100000],
        ])->assertOk();

        $this->assertSame(0, PledgePayment::count());
        $this->assertSame(0, PledgePaymentAttempt::count());
    }

    // ------------------------------------------------ §6–§8 secure pay links

    public function test_pay_page_renders_amount_month_and_masked_phone(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);

        $monthLabel = Carbon::createFromFormat('Y-m', $payment->billing_month)->format('F Y');

        $page = $this->get('/pledges/pay/'.$token)
            ->assertStatus(200)
            ->assertSee('KSh 1,000', false)
            ->assertSee($monthLabel, false)
            ->assertSee('2547*****678', false)
            ->assertSee('PAY KSh 1,000 WITH M-PESA', false)
            ->assertDontSee('0712345678', false);

        // Never cacheable — the page embeds a personal pay link (spec §6).
        $this->assertStringContainsString(
            'no-store',
            (string) $page->headers->get('Cache-Control')
        );
    }

    public function test_pay_page_rejects_invalid_tampered_and_expired_tokens(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);

        // Forged signature.
        $tampered = substr($token, 0, -1).(substr($token, -1) === 'a' ? 'b' : 'a');
        $this->get('/pledges/pay/'.$tampered)->assertStatus(404);

        // Garbage / missing token.
        $this->get('/pledges/pay/not-a-token')->assertStatus(404);

        // Expiry (spec §6): links die after PLEDGE_PAYMENT_LINK_TTL_HOURS.
        $this->travel((int) config('roi.pledge_payment_link_ttl_hours') + 1)->hours();
        $this->get('/pledges/pay/'.$token)->assertStatus(404);
    }

    public function test_pay_token_rejects_amount_drift_after_minting(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);

        $payment->update(['amount_due' => 2000]);

        $this->get('/pledges/pay/'.$token)->assertStatus(404);
        $this->getJson('/api/pledges/pay/'.$token.'/status')->assertStatus(404);
    }

    public function test_pay_endpoint_starts_stk_and_status_reports_pending(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);

        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '254712345678'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('billing_month', $payment->billing_month);

        $status = $this->getJson('/api/pledges/pay/'.$token.'/status')
            ->assertStatus(200)
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('currency', 'KES');
        // Whole floats serialize as integers in PHP's json_encode.
        $this->assertSame(1000, (int) $status->json('amount_due'));
    }

    public function test_second_attempt_gets_fresh_reference_and_cooldown_reuses_inflight_prompt(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);
        $firstRef = $this->latestAttempt()->reference;

        // Immediate re-click: same in-flight attempt (spec §18), no second prompt.
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])
            ->assertStatus(202);
        $this->assertSame($firstRef, $this->latestAttempt()->reference);
        $this->assertSame(1, PledgePaymentAttempt::count());
        Http::assertSentCount(1);

        // After the cooldown, a retry must be a brand-new attempt + reference
        // (spec §7.4) while the month stays a single obligation row (§1).
        $this->travel(PledgeService::ATTEMPT_COOLDOWN_SECONDS + 1)->seconds();
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])
            ->assertStatus(202);

        $this->assertSame(2, PledgePaymentAttempt::count());
        $this->assertSame(1, PledgePayment::count());
        $secondRef = $this->latestAttempt()->reference;
        $this->assertNotSame($firstRef, $secondRef);
        Http::assertSentCount(2);

        // Settling the SECOND attempt pays the month; the first stays terminal.
        $this->postWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => $secondRef,
                'amount' => 100000,
                'currency' => 'KES',
            ],
        ])->assertOk();

        $this->assertSame(PledgePayment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(1, PledgePayment::where('status', PledgePayment::STATUS_PAID)->count());
        $this->assertSame(
            1,
            PledgePaymentAttempt::where('status', PledgePaymentAttempt::STATUS_SUCCEEDED)->count(),
        );
    }

    public function test_pay_when_already_paid_returns_409_without_new_attempt(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $attempt = $this->latestAttempt();
        $token = app(PledgeTokenService::class)->mint($payment);

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => $attempt->reference, 'amount' => 100000, 'currency' => 'KES'],
        ])->assertOk();

        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])
            ->assertStatus(409);

        $this->assertSame(1, PledgePaymentAttempt::count());
        Http::assertSentCount(1); // only the original create-time charge

        $this->postJson('/api/pledges/pay/'.$token)
            ->assertStatus(409)
            ->assertJsonPath('status', 'PAID');
    }

    public function test_client_supplied_amount_is_ignored(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);

        // A hostile client cannot reprice the month (spec §6): no amount field
        // is read from the body — only {phone} is validated.
        $this->postJson('/api/pledges/pay/'.$token, [
            'phone' => '0712345678',
            'amount' => 1,
            'currency' => 'USD',
            'status' => 'PAID',
        ])->assertStatus(202);

        $payment->refresh();
        $this->assertSame(1000.0, (float) $payment->amount_due);
        $this->assertSame('KES', $payment->currency);
        $this->assertNotSame(PledgePayment::STATUS_PAID, $payment->status);
    }

    public function test_pay_endpoint_rejects_invalid_phone(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);

        $this->postJson('/api/pledges/pay/'.$token, ['phone' => 'not-a-number'])
            ->assertStatus(400);

        $this->assertSame(1, PledgePaymentAttempt::count());
    }

    // ------------------------------------------------------------- §6 token

    public function test_token_round_trip_and_tamper_guards(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $service = app(PledgeTokenService::class);

        $token = $service->mint($payment);
        $this->assertTrue($payment->is($service->verify($token)));

        // Tampered payload (amount edited inside the token) fails the HMAC.
        [$body, $sig] = explode('.', $token, 2);
        $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $payload['amt'] = '1';
        $forgedBody = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $this->assertNull($service->verify($forgedBody.'.'.$sig));

        // Unsigned / malformed input.
        $this->assertNull($service->verify(null));
        $this->assertNull($service->verify(''));
        $this->assertNull($service->verify('garbage'));

        // Orphaned payment id (deleted row).
        $payment->delete();
        $this->assertNull($service->verify($token));
    }

    public function test_existing_card_subscription_flow_stays_unaffected(): void
    {
        // Step 2 must not touch Phase B card billing: an unrelated donation
        // webhook still goes down the existing path without pledge rows.
        $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => 'DEMO-DON-'.strtoupper(str_repeat('ef', 16))],
        ])->assertOk();

        $this->assertSame(0, Pledge::count());
        $this->assertSame(0, PledgePayment::count());
        $this->assertSame(0, PledgePaymentAttempt::count());
    }

    // ------------------------------------- gateway failure + partial state (§4)

    public function test_store_gateway_failure_returns_retryable_payload_and_recovers(): void
    {
        $this->chargeScript[] = Http::response(['status' => false, 'message' => 'Service busy'], 500);

        // MINOR-10: a failed dispatch still yields a truthful, retryable
        // payload — the pledge exists, the attempt is failed, the month DUE.
        $response = $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $response->assertJsonPath('charge_error', true)
            ->assertJsonPath('payment.status', 'DUE');

        $this->assertSame(1, Pledge::count());
        $attempt = $this->latestAttempt();
        $this->assertSame(PledgePaymentAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(PledgePayment::STATUS_DUE, $attempt->pledgePayment->status);
        // MINOR-4: no dead reference left pointing at the failed dispatch.
        $this->assertNull($attempt->pledgePayment->fresh()->paystack_reference);

        // Retry (script now empty => success): fresh attempt, PENDING, 202.
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])
            ->assertStatus(202);

        $this->assertSame(2, PledgePaymentAttempt::count());
        $this->assertSame(PledgePayment::STATUS_PENDING, $payment->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_pay_connection_failure_marks_attempt_failed_and_returns_502(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);

        $this->chargeScript[] = new ConnectionException('Could not resolve host');
        $this->travel(PledgeService::ATTEMPT_COOLDOWN_SECONDS + 1)->seconds();

        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])
            ->assertStatus(502)
            ->assertJsonPath('detail', 'We could not reach the payment service. No M-Pesa request was sent. Please try again.');

        $attempt = $this->latestAttempt();
        $this->assertSame(PledgePaymentAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(PledgePayment::STATUS_DUE, $attempt->pledgePayment->fresh()->status);
        $this->assertSame(2, PledgePaymentAttempt::count());
    }

    public function test_charge_failed_after_success_never_downgrades_paid(): void
    {
        // Two attempts on one month, then success on attempt 2 and a late
        // charge.failed for attempt 1 — PAID must survive (spec §9).
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $attempt1 = $this->latestAttempt();
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);

        $this->travel(PledgeService::ATTEMPT_COOLDOWN_SECONDS + 1)->seconds();
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])->assertStatus(202);
        $attempt2 = $this->latestAttempt();
        $this->assertNotSame($attempt1->reference, $attempt2->reference);

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => $attempt2->reference, 'amount' => 100000, 'currency' => 'KES'],
        ])->assertOk();
        $this->assertSame(PledgePayment::STATUS_PAID, $payment->fresh()->status);

        $this->postWebhook([
            'event' => 'charge.failed',
            'data' => ['reference' => $attempt1->reference],
        ])->assertOk();

        $this->assertSame(PledgePayment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(PledgePaymentAttempt::STATUS_FAILED, $attempt1->refresh()->status);
        $this->assertSame(PledgePaymentAttempt::STATUS_SUCCEEDED, $attempt2->refresh()->status);
    }

    public function test_charge_success_webhook_rejects_currency_mismatch(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $attempt = $this->latestAttempt();

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => $attempt->reference, 'amount' => 100000, 'currency' => 'USD'],
        ])->assertOk();

        $this->assertSame(PledgePayment::STATUS_PENDING, $attempt->pledgePayment->fresh()->status);
    }

    public function test_pay_returns_503_when_payments_disabled(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);

        config(['roi.payments_enabled' => false]);

        // MAJOR-1: the kill switch must cover pay() too — no STK dispatch,
        // no attempt churn, while settlements would 503 anyway.
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])
            ->assertStatus(503);

        $this->assertSame(1, PledgePaymentAttempt::count());
        $this->assertSame(PledgePayment::STATUS_PENDING, $payment->fresh()->status);
        Http::assertSentCount(1); // only the create-time charge
    }

    public function test_cancelled_pledge_cannot_start_a_new_charge(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $payment = PledgePayment::sole();
        $payment->pledge->update(['status' => Pledge::STATUS_CANCELLED]);
        $token = app(PledgeTokenService::class)->mint($payment);

        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])
            ->assertStatus(409)
            ->assertJsonPath('detail', 'This pledge is no longer active.');

        $this->assertSame(1, PledgePaymentAttempt::count());
    }

    public function test_stale_attempt_expires_and_payment_returns_to_due(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $attempt = $this->latestAttempt();
        $payment = PledgePayment::sole();

        $this->travel(PledgeService::ABANDONED_ATTEMPT_MINUTES + 1)->minutes();
        app(PledgeService::class)->expireAbandonedAttempts();

        $this->assertSame(PledgePaymentAttempt::STATUS_EXPIRED, $attempt->refresh()->status);
        $this->assertSame(PledgePayment::STATUS_DUE, $payment->fresh()->status);

        // The month is retryable with a brand-new prompt.
        $token = app(PledgeTokenService::class)->mint($payment);
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])->assertStatus(202);
        $this->assertSame(2, PledgePaymentAttempt::count());
        $this->assertSame(PledgePayment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_pay_page_escapes_html_in_supporter_name(): void
    {
        $this->postJson('/api/pledges', $this->storePayload(['name' => '<b>evil</b>name']))
            ->assertStatus(201);
        $token = app(PledgeTokenService::class)->mint(PledgePayment::sole());

        $this->get('/pledges/pay/'.$token)
            ->assertStatus(200)
            ->assertSee('&lt;b&gt;evil&lt;/b&gt;name', false)
            ->assertDontSee('<b>evil</b>name', false);
    }

    // ------------------------------------------------------ rate limiters

    public function test_create_and_pay_endpoints_are_rate_limited(): void
    {
        // pledges-create: 5/min per IP.
        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->postJson('/api/pledges', $this->storePayload())->status();
        }
        $this->assertSame([201, 200, 200, 200, 200, 429], $statuses);

        // pledges-pay POST: 6/min per token (7th request is throttled).
        $token = app(PledgeTokenService::class)->mint(PledgePayment::sole());
        $payStatuses = [];
        for ($i = 0; $i < 7; $i++) {
            $payStatuses[] = $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0712345678'])->status();
        }
        $this->assertSame([202, 202, 202, 202, 202, 202, 429], $payStatuses);
        // Cooldown reused every prompt — only the create-time charge fired.
        Http::assertSentCount(1);
    }

    public function test_pay_page_and_status_have_their_own_rate_limit_buckets(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201);
        $token = app(PledgeTokenService::class)->mint(PledgePayment::sole());

        // Page: 20/min per token — the 21st render is throttled...
        for ($i = 0; $i < 20; $i++) {
            $this->get('/pledges/pay/'.$token)->assertStatus(200);
        }
        $this->get('/pledges/pay/'.$token)->assertStatus(429);

        // ...and because buckets are split, the status endpoint still answers
        // (spec §10 polling must not be starved by page reloads).
        $this->getJson('/api/pledges/pay/'.$token.'/status')->assertStatus(200);

        // Status bucket: 20/min per token — this probe above used slot 1.
        for ($i = 0; $i < 19; $i++) {
            $this->getJson('/api/pledges/pay/'.$token.'/status')->assertStatus(200);
        }
        $this->getJson('/api/pledges/pay/'.$token.'/status')->assertStatus(429);
    }

    public function test_pay_page_has_a_per_ip_backstop_for_distinct_tokens(): void
    {
        // Each bogus token gets its OWN 20/min token bucket, so without an
        // IP backstop a forged-token flood could render unlimited pages/min.
        for ($i = 0; $i < 60; $i++) {
            $this->get('/pledges/pay/bogus-token-'.$i)->assertStatus(404);
        }
        $this->get('/pledges/pay/bogus-token-60')->assertStatus(429);
    }

    // ------------------------------------ phone rebind cap (§7 + §18 abuse)

    public function test_phone_rebind_is_capped_per_hour_to_prevent_stk_spam(): void
    {
        $this->postJson('/api/pledges', $this->storePayload())->assertStatus(201); // attempt 1
        $payment = PledgePayment::sole();
        $token = app(PledgeTokenService::class)->mint($payment);

        // 1st and 2nd corrections are allowed (cap = 2/hour, cooldown bypassed).
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0722222222'])->assertStatus(202); // attempt 2
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0733333333'])->assertStatus(202); // attempt 3

        // 3rd correction within the hour: capped → the cooldown applies
        // instead (in-flight prompt reused, stored number NOT rebound).
        $this->postJson('/api/pledges/pay/'.$token, ['phone' => '0744444444'])->assertStatus(202);

        $this->assertSame('254733333333', (string) Pledge::sole()->phone);
        $this->assertSame(3, PledgePaymentAttempt::count());
        // create + two rebinds only — the capped attempt sent NO third STK.
        Http::assertSentCount(3);
    }
}
