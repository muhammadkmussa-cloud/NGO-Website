<?php

namespace Tests\Feature;

use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression gates for the security assessment fixes (F-02 … F-08, F-11).
 * Each test asserts the patched behavior that closes the filed finding.
 */
class SecurityFixesRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
    }

    protected function login(): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
        ]);

        return $response->json('access_token');
    }

    protected function withAdmin(): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->login());
    }

    // F-04: invalid JWTs must 401 with the standard detail, never 500/stack traces.
    public function test_f04_malformed_tokens_return_401_not_500(): void
    {
        $tokens = [
            'garbage.token.here',
            'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJhQGIuYyJ9.',           // alg=none shape
            'not-a-jwt',
            base64_encode(random_bytes(40)),
        ];
        foreach ($tokens as $token) {
            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/api/admin/stats');
            $response->assertStatus(401)
                ->assertJsonPath('detail', 'Could not validate global administrator credentials');
        }
    }

    // F-02: M-Pesa webhook must fail CLOSED in production with no token configured.
    public function test_f02_mpesa_webhook_fails_closed_in_production_without_token(): void
    {
        config(['roi.environment' => 'production', 'roi.mpesa_webhook_token' => '']);

        $this->postJson('/api/payments/webhook/mpesa', [
            'Body' => ['stkCallback' => ['ResultCode' => 0, 'CheckoutRequestID' => 'ws_CO_X']],
        ])->assertStatus(403);
    }

    public function test_f02_mpesa_webhook_still_allows_sandbox_in_development(): void
    {
        config(['roi.environment' => 'development', 'roi.mpesa_webhook_token' => '']);

        $this->postJson('/api/payments/webhook/mpesa', [
            'Body' => ['stkCallback' => ['ResultCode' => 0, 'CheckoutRequestID' => 'ws_CO_X']],
        ])->assertOk()->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    // F-03: destructive media refresh is admin-only; cron-sync requires a secret in production.
    public function test_f03_media_refresh_requires_admin(): void
    {
        $this->getJson('/api/public/media/refresh')->assertStatus(401);
        $this->withAdmin()->getJson('/api/public/media/refresh')->assertOk();
    }

    public function test_f03_public_media_ignores_refresh_flag_for_anonymous(): void
    {
        // Anonymous refresh flag must not wipe the cache: response stays 200 and
        // the seeded media survives (quota-guard short-circuits in dev anyway,
        // so assert the endpoint did not error and items remain).
        $before = \App\Models\MediaItem::count();
        $this->getJson('/api/public/media?refresh=true')->assertOk();
        $this->assertSame($before, \App\Models\MediaItem::count());
    }

    public function test_h02_channel_videos_ignores_refresh_for_anonymous(): void
    {
        // H-2: the public channel-videos endpoint must not honor the destructive
        // refresh flag for anonymous callers — seeded cache rows survive untouched.
        $before = \App\Models\MediaItem::count();
        $this->assertGreaterThan(0, $before);

        $response = $this->getJson('/api/youtube/channel-videos?refresh=true');
        $response->assertOk();
        $this->assertSame($before, \App\Models\MediaItem::count());
    }

    public function test_h02_channel_videos_refresh_honored_for_admin(): void
    {
        $this->withAdmin()->getJson('/api/youtube/channel-videos?refresh=true')->assertOk();
    }

    public function test_h02_channel_videos_plain_read_spends_no_youtube_quota(): void
    {
        // H-2: with a production-shaped key configured, routine public reads must
        // be served from the local cache — zero outbound googleapis calls.
        config(['roi.youtube_api_key' => 'AIzaRealShapedKeyForTests_1234567890']);
        \Illuminate\Support\Facades\Http::fake();

        $this->getJson('/api/youtube/channel-videos')->assertOk();
        $this->getJson('/api/public/media')->assertOk();

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_h02_refresh_wipe_blocked_anonymous_even_on_live_api_branch(): void
    {
        // Live-API branch (quota guard bypassed via Http::fake): anonymous refresh
        // must NOT execute the destructive MediaItem wipe.
        config(['roi.youtube_api_key' => 'AIzaRealShapedKeyForTests_1234567890']);
        $seededId = \App\Models\MediaItem::first()->youtube_id;

        \Illuminate\Support\Facades\Http::fake([
            'https://www.googleapis.com/youtube/v3/search*' => \Illuminate\Support\Facades\Http::response([
                'items' => [[
                    'id' => ['kind' => 'youtube#video', 'videoId' => 'freshVideo001'],
                    'snippet' => [
                        'title' => 'Fresh Conference Recap',
                        'description' => '',
                        'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/vi/freshVideo001/hq.jpg']],
                    ],
                ]],
            ], 200),
            'https://www.googleapis.com/youtube/v3/videos*' => \Illuminate\Support\Facades\Http::response(['items' => []], 200),
        ]);

        $this->getJson('/api/youtube/channel-videos?refresh=true')->assertOk();
        $this->assertDatabaseHas('media_items', ['youtube_id' => $seededId]);
    }

    public function test_h02_admin_refresh_executes_live_wipe_and_resync(): void
    {
        // Positive control: an admin refresh DOES reach the live branch and wipes.
        config(['roi.youtube_api_key' => 'AIzaRealShapedKeyForTests_1234567890']);

        \Illuminate\Support\Facades\Http::fake([
            'https://www.googleapis.com/youtube/v3/search*' => \Illuminate\Support\Facades\Http::response([
                'items' => [[
                    'id' => ['kind' => 'youtube#video', 'videoId' => 'freshVideo002'],
                    'snippet' => [
                        'title' => 'Mentorship Spotlight',
                        'description' => '',
                        'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/vi/freshVideo002/hq.jpg']],
                    ],
                ]],
            ], 200),
            'https://www.googleapis.com/youtube/v3/videos*' => \Illuminate\Support\Facades\Http::response(['items' => []], 200),
        ]);

        $this->withAdmin()->getJson('/api/youtube/channel-videos?refresh=true')->assertOk();
        $this->assertSame(1, \App\Models\MediaItem::count());
        $this->assertDatabaseHas('media_items', ['youtube_id' => 'freshVideo002']);
    }

    // ------------------------------------------------------------- H-3

    protected function completedFreeOrder(): array
    {
        $type = \App\Models\TicketType::where('price', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'H3 Buyer',
            'buyer_email' => 'h3.buyer@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201)->json();

        return [$checkout['reference'], 'h3.buyer@test.example'];
    }

    public function test_h03_reference_alone_cannot_read_order(): void
    {
        [$reference] = $this->completedFreeOrder();

        // No email / wrong email must look identical to an unknown reference.
        $this->getJson("/api/tickets/orders/{$reference}")->assertStatus(404);
        $this->getJson("/api/tickets/orders/{$reference}?email=attacker@evil.example")->assertStatus(404);
        $this->getJson("/api/tickets/orders/{$reference}/verify")->assertStatus(404);
        $this->getJson("/api/tickets/orders/{$reference}/pass")->assertStatus(404);
        $this->getJson("/api/tickets/orders/{$reference}?email=H3.BUYER@test.example")->assertOk();
    }

    public function test_h03_public_gate_is_open_but_order_access_stays_email_gated(): void
    {
        [$reference] = $this->completedFreeOrder();

        $code = \App\Models\TicketOrder::where('reference', $reference)->first()->tickets->first()->code;
        $this->assertNotNull($code);

        // The email-gated printable pass is gone entirely — no PII-leak vector.
        $this->get("/api/tickets/orders/{$reference}/pass")->assertNotFound();

        // The open gate station inspects any valid issued code (by design, no
        // email needed at the door) but must not confirm or deny unknown codes.
        $this->getJson("/api/gate/tickets/{$code}")->assertOk()->assertJsonPath('code', $code);
        $this->getJson('/api/gate/tickets/DEMO-NOPE-XXXX')->assertNotFound();

        // Accessing the buyer's own order/portal still requires the matching
        // email (enumeration-safe): no email looks identical to an unknown ref.
        $this->getJson("/api/tickets/orders/{$reference}")->assertNotFound();
        $this->getJson("/api/tickets/orders/{$reference}?email=h3.buyer@test.example")->assertOk();
    }

    public function test_h03_stk_retry_requires_email_and_resend_removed(): void
    {
        [, $email] = $this->completedFreeOrder();
        $reference = \App\Models\TicketOrder::where('buyer_email', $email)->first()->reference;

        // The email-resend endpoint no longer exists (tickets are downloaded, not mailed).
        $this->postJson("/api/tickets/orders/{$reference}/resend", [])->assertNotFound();
        $this->postJson("/api/tickets/orders/{$reference}/resend", ['email' => 'attacker@evil.example'])->assertNotFound();

        // STK retry still requires the matching email.
        $this->postJson("/api/tickets/orders/{$reference}/stk-retry", [])->assertStatus(422);
        // Correct email passes the ownership gate and reaches business logic,
        // which rejects a Completed order with 409.
        $this->postJson("/api/tickets/orders/{$reference}/stk-retry", ['email' => $email])->assertStatus(409);
    }

    public function test_h03_payments_verify_sanitizes_pii_without_email(): void
    {
        // H-3 (bypass fix): the donation-ledger mirror must not re-leak buyer PII.
        [$reference, $email] = $this->completedFreeOrder();

        $sanitized = $this->getJson("/api/payments/verify/{$reference}")
            ->assertOk()
            ->assertJsonPath('status', 'Completed')
            ->assertJsonPath('sanitized', true)
            ->json();

        $this->assertArrayNotHasKey('donor_name', $sanitized);
        $this->assertArrayNotHasKey('email', $sanitized);
        $this->assertArrayNotHasKey('tickets', $sanitized);
        $this->assertStringNotContainsString('h3.buyer@test.example', json_encode($sanitized));

        // Confirming the buyer email unlocks the full payload again.
        $this->getJson("/api/payments/verify/{$reference}?email={$email}")
            ->assertOk()
            ->assertJsonPath('email', $email);
    }

    // ------------------------------------------------------------- M-1

    public function test_m01_payment_checkout_is_throttled(): void
    {
        // M-1: donation checkout inserts ledger rows + can hit live gateway APIs.
        // 11th request within a minute must be rejected (limit 10/min).
        $payload = [
            'amount' => 100,
            'gateway' => 'paystack',
            'email' => 'm1@test.example',
        ];
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/payments/checkout', $payload)->assertStatus(200);
        }
        $this->postJson('/api/payments/checkout', $payload)->assertStatus(429);
    }

    public function test_m01_stk_retry_capped_per_order_per_hour(): void
    {
        // M-1: STK prompts are real SMS. Cap 5/hour per order reference even
        // though each individual attempt is a valid, email-confirmed request.
        config(['roi.environment' => 'development']);
        \Illuminate\Support\Facades\Http::fake(); // Daraja token/push calls faked

        $type = \App\Models\TicketType::where('name', 'Youth Delegate')->where('price', '>', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'M1 Buyer',
            'buyer_email' => 'm1.stk@test.example',
            'buyer_phone' => '0710000001',
            'gateway' => 'M-Pesa',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $reference = $checkout->json('reference');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/tickets/orders/{$reference}/stk-retry", ['email' => 'm1.stk@test.example'])
                ->assertStatus(200);
        }
        $this->postJson("/api/tickets/orders/{$reference}/stk-retry", ['email' => 'm1.stk@test.example'])
            ->assertStatus(429);
    }

    public function test_m01_ticket_code_lookup_is_throttled(): void
    {
        // M-1/H-3: lookup exposes attendee PII by ticket code — cap enumeration.
        [$reference] = $this->completedFreeOrder();
        $ticket = \App\Models\TicketOrder::where('reference', $reference)->first()->tickets->first();
        $ticket->update(['code' => 'DEMO-M1CODE']);

        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/tickets/lookup/DEMO-M1CODE')->assertStatus(200);
        }
        $this->getJson('/api/tickets/lookup/DEMO-M1CODE')->assertStatus(429);
    }

    // ------------------------------------------------------------- M-2

    public function test_m02_dev_autocomplete_requires_explicit_flag(): void
    {
        // ENVIRONMENT=development ALONE no longer auto-completes payments or
        // fabricates sandbox checkout URLs — the live gateway is consulted.
        config(['roi.environment' => 'development', 'roi.allow_dev_payment_bypasses' => false]);

        \Illuminate\Support\Facades\Http::fake([
            'https://api.paystack.co/transaction/initialize*' => \Illuminate\Support\Facades\Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/real-shaped-url', 'reference' => 'x'],
            ], 200),
        ]);

        $type = \App\Models\TicketType::where('name', 'Youth Delegate')->where('price', '>', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'M2 Buyer',
            'buyer_email' => 'm2@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);
        $this->assertStringNotContainsString('verified-sandbox', (string) $checkout->json('authorization_url'));
        $reference = $checkout->json('reference');

        // Without the flag, verify consults the (faked, failing) gateway instead.
        \Illuminate\Support\Facades\Http::fake([
            'https://api.paystack.co/*' => \Illuminate\Support\Facades\Http::response(['message' => 'down'], 500),
        ]);
        $this->getJson("/api/payments/verify/{$reference}")->assertStatus(502);
        $this->assertDatabaseHas('ticket_orders', ['reference' => $reference, 'status' => 'Pending Paystack Checkout']);

        // With the explicit flag, sandbox behavior is restored.
        config(['roi.allow_dev_payment_bypasses' => true]);
        \Illuminate\Support\Facades\Http::fake(); // nothing should be called
        $this->getJson("/api/payments/verify/{$reference}")
            ->assertOk()
            ->assertJsonPath('status', 'Completed');
    }

    public function test_m02_unsigned_paystack_webhook_rejected_without_flag(): void
    {
        config(['roi.environment' => 'development', 'roi.allow_dev_payment_bypasses' => false, 'roi.paystack_secret_key' => '']);

        $this->postJson('/api/payments/webhook/paystack', [
            'event' => 'charge.success',
            'data' => ['reference' => 'DEMO-FAKE-REF'],
        ])->assertStatus(400);
    }

    // ------------------------------------------------------------- M-4

    public function test_m04_password_guessing_locks_out_after_ten_failures(): void
    {
        $email = config('roi.admin_email');

        // Prime the M-4 attempt counter (route throttling makes a live loop of
        // 10+ failures impossible inside one minute — that's its job).
        $key = 'pwd-attempts:' . sha1('127.0.0.1|' . $email);
        \Illuminate\Support\Facades\Cache::put($key, 10, now()->addMinutes(15));

        // Even a FULLY VALID attempt is now locked out.
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'admin123',
        ])->assertStatus(429)->assertHeader('Retry-After');

        // Successful logins reset the counter.
        \Illuminate\Support\Facades\Cache::forget($key);
    }

    public function test_m04_wrong_passwords_increment_dedicated_lockout_counter(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $email = config('roi.admin_email');
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'admin123',
        ])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_m04_unknown_emails_use_same_dedicated_lockout_shape(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'intruder@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'intruder@example.com',
            'password' => 'admin123',
        ])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_m04_account_lockout_accumulates_across_client_ips(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $email = config('roi.admin_email');
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.' . ($i + 1)])
                ->postJson('/api/auth/login', [
                    'email' => $email,
                    'password' => 'wrong-password',
                ])->assertStatus(401);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.55.0.1'])
            ->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'admin123',
            ])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_m04_legacy_sha256_hash_upgrades_to_bcrypt_on_login(): void
    {
        $admin = \App\Models\AdminUser::where('email', config('roi.admin_email'))->first();
        $this->assertNotNull($admin);
        // Seeder stores the legacy scheme; force it to be certain.
        $admin->password_hash = hash('sha256', 'admin123');
        $admin->save();

        $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
        ])->assertOk()->assertJsonStructure(['access_token']);

        $admin->refresh();
        $this->assertStringStartsWith('$2y$', (string) $admin->password_hash);

        // Upgraded hash keeps working for the next login too.
        $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
        ])->assertOk();
    }

    public function test_f03_cron_sync_fails_closed_in_production_without_secret(): void
    {
        config(['roi.environment' => 'production', 'roi.youtube_cron_secret' => '']);
        $this->postJson('/api/youtube/cron-sync')->assertStatus(403);
    }

    public function test_f03_cron_sync_accepts_valid_secret_in_production(): void
    {
        config(['roi.environment' => 'production', 'roi.youtube_cron_secret' => 's3cret-cron']);

        $this->postJson('/api/youtube/cron-sync')->assertStatus(403);
        $this->postJson('/api/youtube/cron-sync?secret=s3cret-cron')->assertOk();
        $this->postJson('/api/youtube/cron-sync', [], ['X-Cron-Secret' => 's3cret-cron'])->assertOk();
    }

    // F-05: CSV export neutralizes spreadsheet formulas.
    public function test_f05_csv_export_neutralizes_formula_injection(): void
    {
        \App\Models\Volunteer::create([
            'full_name' => '=2+5EVIL', 'email' => '+cmd@x.example', 'phone' => '@sum(1)',
            'primary_skill' => 'Mentorship', 'availability' => 'Weekends',
        ]);

        $body = $this->withAdmin()->get('/api/admin/volunteers/export')->getContent();

        $this->assertStringContainsString("'=2+5EVIL", $body);
        $this->assertStringContainsString("'+cmd@x.example", $body);
        $this->assertStringContainsString("'@sum(1)", $body);
        $this->assertStringNotContainsString("\n=2+5EVIL", $body);
    }

    // F-06: security headers on SPA and API responses.
    public function test_f06_security_headers_present(): void
    {
        $spa = $this->get('/');
        $spa->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $spa->headers->get('Content-Security-Policy'));

        $api = $this->getJson('/api/health');
        $api->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // F-07: wildcard vercel.app pattern is gone.
    public function test_f07_attacker_vercel_origin_rejected(): void
    {
        $this->withHeader('Origin', 'https://evil.vercel.app')
            ->withHeader('Access-Control-Request-Method', 'GET')
            ->options('/api/public/metrics')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    // F-08: login route is strictly throttled.
    public function test_f08_login_throttled_after_five_attempts_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'admin@example.com', 'password' => 'wrong',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com', 'password' => 'admin123',
        ])->assertStatus(429);
    }

    // F-11: amount bounds and M-Pesa minimum.
    public function test_f11_checkout_rejects_unbounded_amounts_and_currencies(): void
    {
        $base = ['gateway' => 'M-Pesa', 'phone_number' => '0712345678'];

        $this->postJson('/api/payments/checkout', $base + ['amount' => 1e15])
            ->assertStatus(422);
        $this->postJson('/api/payments/checkout', $base + ['amount' => 10, 'currency' => 'USD<script>'])
            ->assertStatus(422);
        $this->postJson('/api/payments/checkout', $base + ['amount' => 0.5])
            ->assertStatus(400)
            ->assertJsonPath('detail', 'M-Pesa contributions must be at least KES 1.');
        $this->postJson('/api/payments/checkout', $base + ['amount' => 500])
            ->assertOk();
    }

    // F-01: production must refuse to boot with a weak/template JWT secret.
    public function test_f01_production_boots_fail_on_weak_jwt_secret(): void
    {
        config(['roi.environment' => 'production']);
        foreach (['roi-super-secret-jwt-signing-key-change-in-production-2026', 'short', ''] as $weak) {
            config(['roi.jwt_secret_key' => $weak]);
            $provider = new \App\Providers\AppServiceProvider($this->app);
            $method = new \ReflectionMethod($provider, 'enforceJwtSecretStrength');
            $method->setAccessible(true);

            try {
                $method->invoke($provider);
                $this->fail("Weak secret '{$weak}' did not hard-fail in production");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('JWT_SECRET_KEY', $e->getMessage());
            }
        }
    }

    public function test_f01_strong_secret_passes_the_guard(): void
    {
        config(['roi.environment' => 'production', 'roi.jwt_secret_key' => base64_encode(random_bytes(32))]);
        $provider = new \App\Providers\AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'enforceJwtSecretStrength');
        $method->setAccessible(true);
        $method->invoke($provider); // must not throw
        $this->assertTrue(true);
    }
}
