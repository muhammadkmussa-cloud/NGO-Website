<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Inquiry;
use App\Models\Leader;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Acceptance suite ported from test_all_backend_functions.py.
 * Exercises every endpoint of the ROI API and asserts FastAPI-compatible
 * status codes, response shapes, and business rules.
 */
class RoiApiAcceptanceTest extends TestCase
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

        $response->assertOk();

        return $response->json('access_token');
    }

    protected function withAdmin(): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->login());
    }

    // --------------------------------------------------------------- Health

    public function test_health_endpoint_reports_online(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'online')
            ->assertJsonPath('location', 'Mombasa, Kenya');
    }

    // ----------------------------------------------------------------- Auth

    public function test_login_succeeds_with_email_and_password_only(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_login_ignores_obsolete_mfa_code_field(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
            'mfa_code' => 'not-a-real-code',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_login_requires_email(): void
    {
        $this->postJson('/api/auth/login', [
            'password' => 'admin123',
        ])->assertStatus(422);
    }

    public function test_login_rejects_unauthorized_email(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'intruder@evil.com', 'password' => 'x',
        ])->assertStatus(401)
            // M-4: uniform failure surface — stage is distinguishable only in audit logs.
            ->assertJsonPath('detail', 'Invalid administrator credentials.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'login failed', 'details' => 'Unauthorized email attempt']);
    }

    public function test_login_requires_password(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
        ])->assertStatus(422);
    }

    public function test_login_rejects_invalid_password(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'), 'password' => 'wrong-password',
        ])->assertStatus(401)->assertJsonPath('detail', 'Invalid administrator credentials.');
    }

    public function test_login_uses_same_detail_for_wrong_email_and_wrong_password(): void
    {
        $wrongEmail = $this->postJson('/api/auth/login', [
            'email' => 'intruder@evil.com',
            'password' => 'admin123',
        ])->assertStatus(401);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertSame($wrongEmail->json('detail'), $wrongPassword->json('detail'));
    }

    public function test_successful_login_clears_password_failure_counter(): void
    {
        $email = strtolower((string) config('roi.admin_email'));
        $key = 'pwd-attempts:' . sha1('127.0.0.1|' . $email);
        $accountKey = 'pwd-account-attempts:' . sha1($email);

        \Illuminate\Support\Facades\Cache::put($key, 3, now()->addMinutes(15));
        \Illuminate\Support\Facades\Cache::put($accountKey, 3, now()->addMinutes(15));

        $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
        ])->assertOk();

        $this->assertNull(\Illuminate\Support\Facades\Cache::get($key));
        $this->assertNull(\Illuminate\Support\Facades\Cache::get($accountKey));
    }

    public function test_configured_password_hash_is_authoritative_over_stale_database_hash(): void
    {
        $admin = \App\Models\AdminUser::where('email', config('roi.admin_email'))->firstOrFail();
        $admin->password_hash = hash('sha256', 'admin123');
        $admin->save();

        config(['roi.admin_password_hash' => hash('sha256', 'rotated-strong-password')]);

        $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
        ])->assertStatus(401)->assertJsonPath('detail', 'Invalid administrator credentials.');

        $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'rotated-strong-password',
        ])->assertOk()->assertJsonStructure(['access_token']);

        $admin->refresh();
        $this->assertStringStartsWith('$2y$', (string) $admin->password_hash);
    }

    public function test_login_succeeds_with_seed_credentials_and_issues_bearer_jwt(): void
    {
        $token = $this->login();
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login succeeded']);
    }

    // ----------------------------------------------------------- Public API

    public function test_public_blog_lists_published_posts(): void
    {
        $response = $this->getJson('/api/public/blog');
        $response->assertOk();
        $posts = $response->json();
        $this->assertCount(3, $posts);

        $first = $posts[0];
        foreach (['id', 'title', 'slug', 'summary', 'content', 'category', 'author', 'is_published', 'created_at', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
    }

    public function test_public_blog_filters_by_category_and_search(): void
    {
        $this->getJson('/api/public/blog?category=Mentorship')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.slug', 'empowering-mombasa-youth-vijana-na-maadili');

        $this->getJson('/api/public/blog?search=Digital Divide')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.slug', 'bridging-the-digital-divide-tech-literacy');

        $this->getJson('/api/public/blog?search=zzz-no-match')->assertOk()->assertJsonCount(0);
    }

    public function test_public_blog_by_slug_and_404(): void
    {
        $this->getJson('/api/public/blog/community-voices-meet-ali-transformed')
            ->assertOk()->assertJsonPath('author', 'ROI Media Team');

        $this->getJson('/api/public/blog/does-not-exist')
            ->assertStatus(404)->assertJsonPath('detail', 'Article not found');
    }

    public function test_public_events_lists_active_only(): void
    {
        $this->getJson('/api/public/events')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.title', 'Vijana Na Maadili Annual Conference 2026')
            ->assertJsonPath('0.date', 'August 14-16, 2026'); // free-text string preserved
    }

    public function test_public_media_orders_featured_first(): void
    {
        $this->getJson('/api/public/media')
            ->assertOk()
            ->assertJsonPath('0.is_featured', true)
            ->assertJsonPath('0.youtube_id', 'LNaLhZAJDSs');
    }

    public function test_public_media_latest_returns_three(): void
    {
        $this->getJson('/api/public/media/latest')->assertOk()->assertJsonCount(3);
    }

    public function test_public_metrics_are_settings_backed(): void
    {
        // Defaults now come from SiteSetting (admin-editable); events_hosted defaults to 2.
        $this->getJson('/api/public/metrics')->assertOk()->assertExactJson([
            'youth_mentored' => 120,
            'events_hosted' => 2,
            'individuals_supported' => 95,
            'active_volunteers' => 45,
        ]);
    }

    public function test_volunteer_submission_persists_with_default_status(): void
    {
        $this->postJson('/api/public/volunteer', [
            'full_name' => 'New Person', 'email' => 'new@test.ke', 'phone' => '+254700111222',
            'primary_skill' => 'Mentorship', 'availability' => 'Weekends',
        ])->assertStatus(201)
            ->assertJsonPath('status', 'Pending Review');

        $this->assertDatabaseHas('volunteers', ['full_name' => 'New Person', 'status' => 'Pending Review']);
    }

    public function test_volunteer_submission_validates_required_fields(): void
    {
        $this->postJson('/api/public/volunteer', ['full_name' => 'Incomplete'])
            ->assertStatus(422)
            ->assertJsonStructure(['detail']);
    }

    public function test_contact_submission_defaults_subject(): void
    {
        $this->postJson('/api/public/contact', [
            'name' => 'Sender', 'email' => 'sender@test.ke', 'message' => 'Jambo',
        ])->assertStatus(201)->assertJsonPath('subject', 'General Inquiry')->assertJsonPath('status', 'New');
    }

    public function test_public_leaders_ordered_by_creation(): void
    {
        Leader::create(['name' => 'First', 'role' => 'Chair', 'bio' => 'B1']);
        Carbon::setTestNow(Carbon::now()->addSecond());
        Leader::create(['name' => 'Second', 'role' => 'Treasurer', 'bio' => 'B2']);

        $this->getJson('/api/public/leaders')
            ->assertOk()
            ->assertJsonPath('0.name', 'First')
            ->assertJsonPath('1.name', 'Second');

        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------- Admin

    public function test_admin_endpoints_reject_missing_token(): void
    {
        foreach (['/api/admin/stats', '/api/admin/volunteers', '/api/admin/inquiries'] as $path) {
            $this->getJson($path)->assertStatus(401)
                ->assertJsonPath('detail', 'Could not validate global administrator credentials');
        }
    }

    public function test_admin_stats_computes_actual_donation_total(): void
    {
        // Seeded donations: 10000 KES + 50 GBP×165 + 50000 KES + 100 USD×130 = 81,250 KES.
        // No vanity floor: the figure reflects what was actually received.
        $this->withAdmin()->getJson('/api/admin/stats')->assertOk()->assertExactJson([
            'total_volunteers' => 3,
            'total_donations_kes' => 81250.0,
            'total_events' => 3,
            'total_articles' => 3,
            'recent_inquiries_count' => 2,
            'system_health' => 'Optimal (Vercel + Supabase Synchronized)',
        ]);
    }

    public function test_admin_can_update_site_content_and_public_sees_it(): void
    {
        $this->withAdmin()->putJson('/api/admin/site', [
            'hero_title' => 'Youth Rising 2026',
            'metric_events_hosted' => 2,
            'metric_youth_mentored' => 500,
        ])->assertOk()->assertJsonPath('hero.title', 'Youth Rising 2026');

        $this->getJson('/api/public/site')
            ->assertOk()
            ->assertJsonPath('hero.title', 'Youth Rising 2026')
            ->assertJsonPath('metrics.youth_mentored', 500)
            ->assertJsonPath('metrics.events_hosted', 2);
    }

    public function test_admin_blog_create_generates_unique_slugs(): void
    {
        $payload = ['title' => 'Empowering Mombasa Youth: The Journey of Vijana Na Maadili', 'summary' => 'S', 'content' => 'C'];

        $first = $this->withAdmin()->postJson('/api/admin/blog', $payload)->assertOk();
        $second = $this->withAdmin()->postJson('/api/admin/blog', $payload)->assertOk();

        $slugOne = $first->json('slug');
        $this->assertSame("{$slugOne}-1", $second->json('slug'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'blog created']);
    }

    public function test_admin_blog_update_regenerates_slug_on_title_change(): void
    {
        $postId = \App\Models\BlogPost::first()->id;
        $this->withAdmin()->putJson("/api/admin/blog/{$postId}", ['title' => 'Brand New Heading'])
            ->assertOk()
            ->assertJsonPath('slug', 'brand-new-heading');
    }

    public function test_admin_blog_delete_returns_204(): void
    {
        $postId = \App\Models\BlogPost::first()->id;
        $this->withAdmin()->deleteJson("/api/admin/blog/{$postId}")->assertStatus(204);
        $this->assertDatabaseMissing('blog_posts', ['id' => $postId]);
        $this->getJson('/api/public/blog')->assertOk(); // sanity: API still alive
    }

    public function test_admin_event_crud_lifecycle(): void
    {
        $created = $this->withAdmin()->postJson('/api/admin/events', [
            'title' => 'Beach Mentorship Day', 'date' => 'Dec 1, 2026', 'description' => 'Desc',
        ])->assertOk()->assertJsonPath('time', '09:00 AM EAT')->assertJsonPath('location', 'Mombasa, Kenya');

        $id = $created->json('id');

        $this->withAdmin()->putJson("/api/admin/events/{$id}", ['title' => 'Renamed Event'])
            ->assertOk()->assertJsonPath('title', 'Renamed Event');

        $this->withAdmin()->deleteJson("/api/admin/events/{$id}")->assertStatus(204);
        $this->withAdmin()->deleteJson("/api/admin/events/{$id}")->assertStatus(404)
            ->assertJsonPath('detail', 'Event not found');
    }

    public function test_admin_volunteers_filter_search_and_csv_export(): void
    {
        $this->withAdmin()->getJson('/api/admin/volunteers?skill=Graphic')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.full_name', 'Grace Achieng');

        $this->withAdmin()->getJson('/api/admin/volunteers?search=salim.omari')
            ->assertOk()->assertJsonCount(1);

        $response = $this->withAdmin()->get('/api/admin/volunteers/export');
        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename=roi_volunteers_registry.csv');

        $body = trim($response->getContent());
        $this->assertStringContainsString('Salim Omari', $body);
        $this->assertStringContainsString('Registered At', $body);
        $this->assertDatabaseHas('audit_logs', ['action' => 'registry exported']);
    }

    public function test_admin_inquiry_read_is_idempotent_for_unknown_ids(): void
    {
        $inquiryId = Inquiry::first()->id;
        $this->withAdmin()->putJson("/api/admin/inquiries/{$inquiryId}/read")
            ->assertOk()->assertExactJson(['status' => 'success']);

        $this->assertDatabaseHas('inquiries', ['id' => $inquiryId, 'status' => 'Resolved']);

        // Parity: unknown ID still reports success.
        $this->withAdmin()->putJson('/api/admin/inquiries/99999/read')
            ->assertOk()->assertExactJson(['status' => 'success']);
    }

    public function test_admin_media_sync_flags_or_stubs_featured_video(): void
    {
        // Existing video override
        $this->withAdmin()->postJson('/api/admin/media/sync?featured_youtube_id=L_LUpnjgPso')->assertOk()
            ->assertJsonPath('message', 'Local YouTube metadata cache successfully synchronized.');
        $this->assertDatabaseHas('media_items', ['youtube_id' => 'L_LUpnjgPso', 'is_featured' => true]);
        $this->assertDatabaseHas('media_items', ['youtube_id' => 'LNaLhZAJDSs', 'is_featured' => false]);

        // Unknown ID creates a stub
        $this->withAdmin()->postJson('/api/admin/media/sync?featured_youtube_id=newID99')->assertOk();
        $this->assertDatabaseHas('media_items', [
            'youtube_id' => 'newID99',
            'title' => 'Featured ROI Empowerment Special',
            'thumbnail_url' => 'https://img.youtube.com/vi/newID99/maxresdefault.jpg',
        ]);
    }

    public function test_admin_leader_crud_and_public_visibility(): void
    {
        $created = $this->withAdmin()->postJson('/api/admin/leaders', [
            'name' => 'Fatuma Bakari', 'role' => 'Lead Coordinator', 'bio' => 'Leads all programs.',
        ])->assertOk();

        $id = $created->json('id');
        $this->getJson('/api/public/leaders')->assertOk()->assertJsonPath('0.name', 'Fatuma Bakari');

        $this->withAdmin()->putJson("/api/admin/leaders/{$id}", ['role' => 'Executive Director'])->assertOk()
            ->assertJsonPath('role', 'Executive Director');

        $this->withAdmin()->deleteJson("/api/admin/leaders/{$id}")->assertStatus(204);
    }

    public function test_audit_logs_endpoint_returns_latest_entries(): void
    {
        $this->login(); // generates audit rows
        $this->withAdmin()->getJson('/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonStructure([['id', 'admin_email', 'action', 'details', 'created_at']]);
    }

    // ------------------------------------------------------------- Payments

    public function test_mpesa_checkout_sandbox_dispatches_stk_prompt(): void
    {
        $response = $this->postJson('/api/payments/checkout', [
            'donor_name' => 'Test Donor', 'amount' => 1500, 'currency' => 'KES',
            'gateway' => 'M-Pesa', 'phone_number' => '0712345678',
        ])->assertOk();

        $reference = $response->json('reference');
        $this->assertMatchesRegularExpression('/^ROI-M-P-[0-9A-F]{32}$/', $reference);
        $this->assertSame('STK Prompt Dispatched', $response->json('status'));
        $this->assertStringStartsWith('ws_CO_SIM_', (string) $response->json('checkout_request_id'));

        $this->assertDatabaseHas('donations', ['reference' => $reference, 'status' => 'STK Prompt Dispatched']);
    }

    public function test_mpesa_checkout_normalizes_phone_formats(): void
    {
        // [raw input, normalized Daraja number] — pairs avoid PHP's numeric-string key casting.
        $cases = [
            ['0712345678', '254712345678'],
            ['254712345678', '254712345678'],
            ['712345678', '254712345678'],
        ];
        foreach ($cases as [$input, $expected]) {
            $response = $this->postJson('/api/payments/checkout', [
                'amount' => 10, 'gateway' => 'M-Pesa', 'phone_number' => $input,
            ])->assertOk();
            $this->assertStringContainsString($expected, (string) $response->json('customer_message'), "Input {$input}");
        }
    }

    public function test_mpesa_checkout_rejects_invalid_phone(): void
    {
        $this->postJson('/api/payments/checkout', [
            'amount' => 100, 'gateway' => 'M-Pesa', 'phone_number' => '123',
        ])->assertStatus(400)->assertJsonPath(
            'detail',
            'Invalid M-Pesa phone number format. Please enter a valid Kenyan mobile number (e.g., 0712345678 or 254712345678).'
        );

        $this->postJson('/api/payments/checkout', ['amount' => 100, 'gateway' => 'M-Pesa'])
            ->assertStatus(400)->assertJsonPath('detail', 'Phone number is required for M-Pesa STK Push checkout.');
    }

    public function test_paystack_checkout_sandbox_generates_url_and_verifies(): void
    {
        $response = $this->postJson('/api/payments/checkout', [
            'email' => 'donor@example.com', 'amount' => 25, 'currency' => 'USD', 'gateway' => 'Paystack',
        ])->assertOk();

        $reference = $response->json('reference');
        $this->assertMatchesRegularExpression('/^ROI-PAY-[0-9A-F]{32}$/', $reference);
        $this->assertSame('https://checkout.paystack.com/verified-sandbox-' . $reference, $response->json('authorization_url'));

        // Sandbox verify completes instantly.
        $this->getJson("/api/payments/verify/{$reference}")->assertOk()->assertJsonPath('status', 'Completed');
    }

    public function test_checkout_rejects_unsupported_gateway(): void
    {
        $this->postJson('/api/payments/checkout', ['amount' => 10, 'gateway' => 'Bitcoin'])
            ->assertStatus(400)
            ->assertJsonPath('detail', 'Unsupported payment gateway. Only Paystack and M-Pesa are accepted.');
    }

    public function test_verify_unknown_reference_404(): void
    {
        $this->getJson('/api/payments/verify/UNKNOWN-REF')
            ->assertStatus(404)
            ->assertJsonPath('detail', 'Payment transaction reference not found');
    }

    public function test_mpesa_webhook_matches_by_checkout_request_id(): void
    {
        $donation = Donation::create([
            'amount' => 500, 'currency' => 'KES', 'gateway' => 'M-Pesa KCB', 'reference' => 'ROI-MPE-TEST0001',
            'checkout_request_id' => 'ws_CO_ABC123', 'status' => 'STK Prompt Dispatched',
        ]);

        config(['roi.mpesa_webhook_token' => '']);

        $this->postJson('/api/payments/webhook/mpesa', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 0,
                'CheckoutRequestID' => 'ws_CO_ABC123',
            ]],
        ])->assertOk()->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $this->assertDatabaseHas('donations', ['id' => $donation->id, 'status' => 'Completed']);
    }

    public function test_mpesa_webhook_records_failure_result_codes(): void
    {
        $donation = Donation::create([
            'amount' => 500, 'currency' => 'KES', 'gateway' => 'M-Pesa KCB', 'reference' => 'ROI-MPE-TEST0002',
            'merchant_request_id' => 'MRC_XYZ', 'status' => 'STK Prompt Dispatched',
        ]);

        config(['roi.mpesa_webhook_token' => '']);

        $this->postJson('/api/payments/webhook/mpesa', [
            'Body' => ['stkCallback' => ['ResultCode' => 1032, 'MerchantRequestID' => 'MRC_XYZ']],
        ])->assertOk();

        $this->assertDatabaseHas('donations', ['id' => $donation->id, 'status' => 'Failed (Daraja ResultCode 1032)']);
    }

    public function test_mpesa_webhook_hardened_against_wrong_token(): void
    {
        config(['roi.mpesa_webhook_token' => 's3cret-token']);

        $this->postJson('/api/payments/webhook/mpesa?token=bad', ['Body' => []])
            ->assertStatus(403);

        // Correct token passes structural validation and ACKs deterministically.
        $this->postJson('/api/payments/webhook/mpesa?token=s3cret-token', ['Body' => []])
            ->assertOk()->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function test_paystack_webhook_enforces_hmac_signature_when_secret_configured(): void
    {
        config(['roi.paystack_secret_key' => 'sk_test_acceptance']);
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'ROI-PAY-77B33C22']]);

        // Invalid signature rejected even in dev once a secret is configured.
        $this->postJson('/api/payments/webhook/paystack', json_decode($raw, true))
            ->assertStatus(400)->assertJsonPath('detail', 'Invalid Paystack signature verification');

        // Valid HMAC-SHA512 signature marks donation completed.
        $signature = hash_hmac('sha512', $raw, 'sk_test_acceptance');
        $this->call(
            'POST',
            '/api/payments/webhook/paystack',
            [],
            [],
            [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => $signature],
            $raw
        )->assertOk()->assertExactJson(['status' => 'success']);

        $this->assertDatabaseHas('donations', ['reference' => 'ROI-PAY-77B33C22', 'status' => 'Completed']);
    }

    public function test_paybills_endpoint_exposes_offline_channels(): void
    {
        $shortcode = config('roi.mpesa_shortcode');

        $this->getJson('/api/payments/paybills')->assertOk()->assertSimilarJson([
            'enabled' => true,
            'business_name' => 'REACHING OUT INITIATIVE',
            'kcb_mpesa' => [
                'channel' => 'M-Pesa Paybill via KCB Bank',
                'paybill' => $shortcode,
                'account' => '000004',
                'business_name' => 'REACHING OUT INITIATIVE',
            ],
            'equity_bank' => [
                'channel' => 'M-Pesa / Airtel Money / Equitel via Equity Bank',
                'paybill' => '000002',
                'account' => '000003',
                'business_name' => 'REACHING OUT INITIATIVE',
            ],
        ]);
    }

    // ------------------------------------------------------------- YouTube

    public function test_youtube_channel_videos_quota_guard_serves_cache(): void
    {
        $response = $this->getJson('/api/youtube/channel-videos')->assertOk();

        $this->assertSame('synchronized_local_cache', $response->json('sync_status.status'));
        $videos = $response->json('videos');
        $this->assertCount(3, $videos);
        $this->assertArrayHasKey('youtube_id', $videos[0]);
        $this->assertTrue($videos[0]['is_featured']);
    }

    public function test_youtube_cron_sync_degrades_gracefully_without_network(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        // With a configured key but failing network, sync degrades to fallback cache.
        config(['roi.youtube_api_key' => 'AIza_test_realistic_key']);
        $this->postJson('/api/youtube/cron-sync')->assertOk()->assertJsonPath('status', 'fallback_cache');
    }

    public function test_iso_duration_parser_matches_python_behavior(): void
    {
        $service = new \App\Services\YouTubeSyncService();

        $this->assertSame('12:45', $service->parseIso8601Duration('PT12M45S'));
        $this->assertSame('1:02:03', $service->parseIso8601Duration('PT1H2M3S'));
        $this->assertSame('5:30', $service->parseIso8601Duration('garbage'));
    }
}
