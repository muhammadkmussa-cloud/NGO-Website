<?php

namespace Tests\Feature;

use App\Models\TicketOrder;
use App\Models\TicketType;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 12 — integration testing & production hardening.
 */
class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config(['roi.environment' => 'test']);
    }

    public function test_ready_probe_reports_database(): void
    {
        $this->getJson('/api/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', 'ok');
    }

    public function test_health_in_production_omits_internal_engine_details(): void
    {
        config(['roi.environment' => 'production']);

        $json = $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'online')
            ->assertJsonPath('location', 'Harbor City, Kenya')
            ->json();

        $this->assertArrayNotHasKey('database_engine', $json);
        $this->assertArrayNotHasKey('environment', $json);
    }

    public function test_health_in_test_still_includes_environment(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('environment', 'test');
    }

    public function test_request_id_is_echoed_and_generated(): void
    {
        $this->withHeader('X-Request-Id', 'roi-test-correlation-01')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'roi-test-correlation-01');

        $generated = $this->getJson('/api/ready');
        $generated->assertOk();
        $this->assertNotEmpty($generated->headers->get('X-Request-Id'));
    }

    public function test_permissions_policy_and_hsts_in_production(): void
    {
        $dev = $this->getJson('/api/health');
        $dev->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->assertNull($dev->headers->get('Strict-Transport-Security'));

        config(['roi.environment' => 'production']);
        $this->getJson('/api/health')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_paystack_webhook_fails_closed_in_production_without_secret(): void
    {
        config(['roi.environment' => 'production', 'roi.paystack_secret_key' => '']);

        $this->postJson('/api/payments/webhook/paystack', [
            'event' => 'charge.success',
            'data' => ['reference' => 'DEMO-PAY-NONE'],
        ])->assertStatus(403);
    }

    public function test_duplicate_paystack_webhook_is_idempotent(): void
    {
        $type = TicketType::where('name', 'Youth Delegate')->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Idem Buyer',
            'buyer_email' => 'idem@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $reference = $checkout->json('reference');
        config(['roi.paystack_secret_key' => 'sk_ticket_pay']);
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);
        $signature = hash_hmac('sha512', $raw, 'sk_ticket_pay');

        $headers = ['HTTP_X_PAYSTACK_SIGNATURE' => $signature];
        $this->call('POST', '/api/payments/webhook/paystack', [], [], [], $headers, $raw)->assertOk();
        $this->call('POST', '/api/payments/webhook/paystack', [], [], [], $headers, $raw)->assertOk();

        $order = TicketOrder::where('reference', $reference)->first();
        $this->assertSame('Completed', $order->status);
        $this->assertSame(1, $order->tickets()->count());
    }

    public function test_end_to_end_ticket_and_solution_inquiry(): void
    {
        $type = TicketType::where('name', 'Standard Seat')->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'E2E Buyer',
            'buyer_email' => 'e2e@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $reference = $checkout->json('reference');
        $this->getJson("/api/payments/verify/{$reference}")
            ->assertOk()
            ->assertJsonPath('status', 'Completed');

        $this->getJson("/api/tickets/orders/{$reference}?email=e2e@test.example")
            ->assertOk()
            ->assertJsonPath('status', 'Completed');

        $this->postJson('/api/tickets/recover', ['email' => 'e2e@test.example'])->assertOk();

        $solutionId = \App\Models\DigitalSolution::where('slug', 'community-event-ticketing')->value('id');
        $this->postJson('/api/public/solutions/inquire', [
            'digital_solution_id' => $solutionId,
            'name' => 'E2E Org',
            'email' => 'org@test.example',
            'organization' => 'Coast Lab',
            'message' => 'Need ticketing for a 400-seat hall.',
        ])->assertStatus(201)->assertJsonPath('status', 'New');

        $this->getJson('/api/public/portfolio?featured=1')->assertOk();
    }

    public function test_production_refuses_debug_and_demo_password(): void
    {
        // M-2 flag comes from phpunit.xml for sandbox tests; production sims must clear it.
        config(['roi.environment' => 'production', 'roi.allow_dev_payment_bypasses' => false, 'app.debug' => true]);
        $provider = new \App\Providers\AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'enforceProductionHardening');
        $method->setAccessible(true);

        try {
            $method->invoke($provider);
            $this->fail('APP_DEBUG=true did not hard-fail in production');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('APP_DEBUG', $e->getMessage());
        }

        config(['app.debug' => false, 'roi.admin_password_hash' => hash('sha256', 'admin123')]);
        try {
            $method->invoke($provider);
            $this->fail('Demo password hash did not hard-fail in production');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ADMIN_PASSWORD_HASH', $e->getMessage());
        }

        config(['roi.admin_password_hash' => \Illuminate\Support\Facades\Hash::make('rotated-strong-password')]);
        $method->invoke($provider);
        $this->assertTrue(true);
    }

    public function test_production_refuses_legacy_sha256_admin_password_hash(): void
    {
        config([
            'roi.environment' => 'production',
            'roi.allow_dev_payment_bypasses' => false,
            'app.debug' => false,
            'roi.admin_password_hash' => hash('sha256', 'rotated-strong-password'),
        ]);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'enforceProductionHardening');
        $method->setAccessible(true);

        try {
            $method->invoke($provider);
            $this->fail('Legacy SHA-256 admin password hash did not hard-fail in production');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('modern password hash', $e->getMessage());
        }
    }

    public function test_production_refuses_modern_hash_of_demo_admin_password(): void
    {
        $provider = new \App\Providers\AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'enforceProductionHardening');
        $method->setAccessible(true);

        foreach ([
            \Illuminate\Support\Facades\Hash::make('admin123'),
            password_hash('admin123', PASSWORD_ARGON2ID),
        ] as $hash) {
            config([
                'roi.environment' => 'production',
                'roi.allow_dev_payment_bypasses' => false,
                'app.debug' => false,
                'roi.admin_password_hash' => $hash,
            ]);

            try {
                $method->invoke($provider);
                $this->fail('Modern hash of demo admin password did not hard-fail in production');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('seeded demo password', $e->getMessage());
            }
        }
    }

    public function test_m02_production_refuses_dev_bypass_flag(): void
    {
        config([
            'roi.environment' => 'production',
            'roi.allow_dev_payment_bypasses' => true,
            'app.debug' => false,
        ]);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'enforceProductionHardening');
        $method->setAccessible(true);

        try {
            $method->invoke($provider);
            $this->fail('ALLOW_DEV_PAYMENT_BYPASSES did not hard-fail in production');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ALLOW_DEV_PAYMENT_BYPASSES', $e->getMessage());
        }
    }
}
