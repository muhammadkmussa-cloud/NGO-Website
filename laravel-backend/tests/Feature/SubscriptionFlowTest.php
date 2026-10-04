<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\SiteSetting;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase B — real monthly Paystack subscriptions:
 * plan create-or-get + checkout wiring, webhook lifecycle linking,
 * renewal ledgering, and the hosted manage-link endpoint.
 */
class SubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config([
            'roi.environment' => 'test',
            'roi.allow_dev_payment_bypasses' => false,
            'roi.paystack_secret_key' => 'sk_test_sub',
        ]);
        Http::preventStrayRequests();
    }

    /** Signed Paystack webhook POST (HMAC-SHA512 over the raw body). */
    private function postSigned(array $payload): TestResponse
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $this->call(
            'POST',
            '/api/payments/webhook/paystack',
            [],
            [],
            [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_sub')],
            $raw
        );
    }

    private function fakePaystack(): void
    {
        Http::fake([
            'https://api.paystack.co/plan' => Http::response([
                'status' => true,
                'message' => 'Plan created',
                'data' => ['plan_code' => 'PLN_MONTHLY_KES_500'],
            ], 200),
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/sub-phase-b',
                    'access_code' => 'sub_phase_b',
                    'reference' => 'roi-test',
                ],
            ], 200),
        ]);
    }

    // ------------------------------------------------------------ Checkout

    public function test_monthly_checkout_creates_plan_and_passes_plan_code(): void
    {
        $this->fakePaystack();

        $response = $this->postJson('/api/payments/checkout', [
            'email' => 'monthly@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
        ]);

        $response->assertOk()
            ->assertJsonPath('frequency', 'monthly')
            ->assertJsonPath('authorization_url', 'https://checkout.paystack.com/sub-phase-b');

        // Plan created with monthly interval in minor units.
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/plan')
                && $request['interval'] === 'monthly'
                && $request['amount'] === 50000
                && $request['currency'] === 'KES';
        });

        // Initialize carries the plan code — this is what makes it recurring.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/transaction/initialize')
                && ($request['plan'] ?? null) === 'PLN_MONTHLY_KES_500'
                && $request['amount'] === 50000;
        });

        $this->assertDatabaseHas('donations', [
            'email' => 'monthly@test.ke',
            'frequency' => 'monthly',
            'currency' => 'KES',
            'status' => 'Pending Paystack Checkout',
            'subscription_code' => null,
        ]);

        $this->assertSame('PLN_MONTHLY_KES_500', SiteSetting::query()
            ->where('key', 'paystack_plan:monthly:KES:50000')
            ->value('value'));
    }

    public function test_monthly_checkout_requires_an_email(): void
    {
        $this->fakePaystack();

        // Monthly without an email is rejected before any gateway call.
        $missing = $this->postJson('/api/payments/checkout', [
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
        ]);

        $missing->assertStatus(422)
            ->assertJsonFragment(['loc' => ['body', 'email'], 'type' => 'value_error']);

        Http::assertNothingSent();

        // A one-time gift without an email stays allowed (sentinel fallback).
        $this->postJson('/api/payments/checkout', [
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
        ])->assertOk();

        // Monthly with an email succeeds.
        $this->postJson('/api/payments/checkout', [
            'email' => 'monthly@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
        ])->assertOk();
    }

    public function test_monthly_checkout_reuses_cached_plan_code(): void
    {
        SiteSetting::query()->create([
            'key' => 'paystack_plan:monthly:KES:50000',
            'value' => 'PLN_CACHED',
        ]);
        $this->fakePaystack();

        $this->postJson('/api/payments/checkout', [
            'email' => 'cached@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
        ])->assertOk();

        // Only initialize is called — the cached plan code is reused.
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/transaction/initialize')
                && ($request['plan'] ?? null) === 'PLN_CACHED';
        });
    }

    public function test_monthly_checkout_fails_friendly_when_plan_creation_fails(): void
    {
        Http::fake([
            'https://api.paystack.co/plan' => Http::response(['status' => false, 'message' => 'boom'], 500),
        ]);

        $this->postJson('/api/payments/checkout', [
            'email' => 'planfail@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
        ])
            ->assertStatus(502)
            ->assertJsonPath('detail', 'We could not set up the monthly pledge. Please try again, or choose a one-time contribution.');

        // No ledger row and no cached plan code when setup fails.
        $this->assertDatabaseMissing('donations', ['email' => 'planfail@test.ke']);
        $this->assertNull(SiteSetting::query()->where('key', 'like', 'paystack_plan:%')->value('value'));
    }

    public function test_monthly_checkout_rejects_mpesa(): void
    {
        $this->postJson('/api/payments/checkout', [
            'email' => 'monthly.mpesa@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'M-Pesa',
            'phone_number' => '0712345678',
            'frequency' => 'monthly',
        ])
            ->assertStatus(400)
            ->assertJsonPath('detail', 'Monthly pledges require a card. Please choose One Time for M-Pesa.');

        $this->assertDatabaseMissing('donations', ['email' => 'monthly.mpesa@test.ke']);
    }

    public function test_one_time_checkout_does_not_create_a_plan(): void
    {
        $this->fakePaystack();

        $this->postJson('/api/payments/checkout', [
            'email' => 'onetime@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'one-time',
        ])->assertOk();

        // Initialize only — no /plan call for one-time gifts.
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return ! array_key_exists('plan', $request->data());
        });
        $this->assertNull(SiteSetting::query()->where('key', 'like', 'paystack_plan:%')->value('value'));
    }

    // ------------------------------------------------- subscription.create

    public function test_subscription_create_links_pending_monthly_donation(): void
    {
        Donation::create([
            'donor_name' => 'Monthly Mary',
            'email' => 'mary@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-SUBCREATE01',
            'status' => 'Completed',
        ]);

        $this->postSigned([
            'event' => 'subscription.create',
            'data' => [
                'subscription_code' => 'SUB_LINKME',
                'email_token' => 'eml_tok_1',
                'status' => 'active',
                'amount' => 50000,
                'next_payment_date' => '2026-11-01T00:00:00.000Z',
                'customer' => ['email' => 'mary@test.ke'],
                'plan' => ['plan_code' => 'PLN_MONTHLY_KES_500', 'amount' => 50000, 'currency' => 'KES'],
            ],
        ])->assertOk()->assertExactJson(['status' => 'success']);

        $this->assertDatabaseHas('donations', [
            'reference' => 'ROI-PAY-SUBCREATE01',
            'subscription_code' => 'SUB_LINKME',
            'subscription_token' => 'eml_tok_1',
            'subscription_status' => 'active',
            'next_payment_date' => '2026-11-01T00:00:00.000Z',
        ]);
    }

    public function test_subscription_create_webhook_is_idempotent(): void
    {
        Donation::create([
            'email' => 'idem@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-IDEMP01',
            'status' => 'Completed',
        ]);

        $payload = [
            'event' => 'subscription.create',
            'data' => [
                'subscription_code' => 'SUB_IDEM',
                'email_token' => 'tok_idem',
                'status' => 'active',
                'amount' => 50000,
                'customer' => ['email' => 'idem@test.ke'],
                'plan' => ['plan_code' => 'PLN_X', 'currency' => 'KES'],
            ],
        ];

        $this->postSigned($payload)->assertOk();
        $this->postSigned($payload)->assertOk();

        $this->assertSame(1, Donation::where('subscription_code', 'SUB_IDEM')->count());
        $this->assertSame(1, Donation::where('reference', 'ROI-PAY-IDEMP01')->count());
    }

    public function test_subscription_create_webhook_never_guesses_an_unmatched_link(): void
    {
        Donation::create([
            'email' => 'someone.else@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-UNMATCHED',
            'status' => 'Pending Paystack Checkout',
        ]);

        $this->postSigned([
            'event' => 'subscription.create',
            'data' => [
                'subscription_code' => 'SUB_ORPHAN',
                'email_token' => 'tok_orphan',
                'status' => 'active',
                'amount' => 99900,
                'customer' => ['email' => 'nobody@test.ke'],
                'plan' => ['plan_code' => 'PLN_Y', 'currency' => 'KES'],
            ],
        ])->assertOk();

        // Accepted, but no donation was linked to the orphan code.
        $this->assertDatabaseMissing('donations', ['subscription_code' => 'SUB_ORPHAN']);
        $this->assertNull(Donation::where('reference', 'ROI-PAY-UNMATCHED')->value('subscription_code'));
    }

    // ------------------------------------------------ lifecycle state sync

    public function test_lifecycle_events_update_subscription_status(): void
    {
        Donation::create([
            'email' => 'lifecycle@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-LIFE01',
            'status' => 'Completed',
            'subscription_code' => 'SUB_LIFE',
            'subscription_status' => 'active',
        ]);

        $base = fn (array $extra) => array_merge([
            'data' => ['subscription_code' => 'SUB_LIFE'],
        ], $extra);

        $this->postSigned($base(['event' => 'subscription.disable']))->assertOk();
        $this->assertDatabaseHas('donations', ['subscription_code' => 'SUB_LIFE', 'subscription_status' => 'disabled']);

        $this->postSigned($base(['event' => 'subscription.not_renew']))->assertOk();
        $this->assertDatabaseHas('donations', ['subscription_code' => 'SUB_LIFE', 'subscription_status' => 'cancelling']);

        $this->postSigned($base(['event' => 'invoice.payment_failed']))->assertOk();
        $this->assertDatabaseHas('donations', ['subscription_code' => 'SUB_LIFE', 'subscription_status' => 'past_due']);
    }

    // ---------------------------------------------------- renewal ledgering

    public function test_renewal_charge_ledgers_one_row_idempotently(): void
    {
        Donation::create([
            'email' => 'renew@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-PARENT01',
            'status' => 'Completed',
            'subscription_code' => 'SUB_REN',
            'subscription_status' => 'active',
            'next_payment_date' => '2026-11-01T00:00:00.000Z',
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'ren-charge-001',
                'amount' => 50000,
                'subscription' => [
                    'subscription_code' => 'SUB_REN',
                    'email_token' => 'tok_ren',
                    'next_payment_date' => '2026-12-01T00:00:00.000Z',
                ],
            ],
        ];

        $renewalReference = 'ROI-REN-'.strtoupper(substr(hash('sha256', 'ren-charge-001'), 0, 32));

        $this->postSigned($payload)->assertOk();
        $this->assertDatabaseHas('donations', [
            'reference' => $renewalReference,
            'subscription_code' => 'SUB_REN',
            'frequency' => 'monthly',
            'status' => 'Completed',
            'amount' => 500,
        ]);

        // Webhook retry must not double-credit the ledger.
        $this->postSigned($payload)->assertOk();
        $this->assertSame(1, Donation::where('subscription_code', 'SUB_REN')->where('reference', 'like', 'ROI-REN-%')->count());

        // Parent schedule refreshed from the charge.
        $this->assertDatabaseHas('donations', [
            'reference' => 'ROI-PAY-PARENT01',
            'next_payment_date' => '2026-12-01T00:00:00.000Z',
            'subscription_status' => 'active',
        ]);
    }

    public function test_invoice_update_paid_refreshes_parent_without_new_ledger_row(): void
    {
        Donation::create([
            'email' => 'invoice@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-INV01',
            'status' => 'Completed',
            'subscription_code' => 'SUB_INV',
            'subscription_status' => 'past_due',
        ]);

        $before = Donation::count();

        $this->postSigned([
            'event' => 'invoice.update',
            'data' => [
                'invoice_code' => 'INV_123',
                'paid' => true,
                'subscription' => ['subscription_code' => 'SUB_INV'],
                'next_payment_date' => '2027-01-01T00:00:00.000Z',
            ],
        ])->assertOk();

        // No double credit: charge.success owns the ledger row.
        $this->assertSame($before, Donation::count());
        $this->assertDatabaseHas('donations', [
            'reference' => 'ROI-PAY-INV01',
            'subscription_status' => 'active',
            'next_payment_date' => '2027-01-01T00:00:00.000Z',
        ]);
    }

    // ------------------------------------------------------- C-1 rethrow

    public function test_renewal_non_unique_db_failure_returns_5xx_for_retry(): void
    {
        Donation::create([
            'email' => 'rethrow@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-RETHROW01',
            'status' => 'Completed',
            'subscription_code' => 'SUB_RTH',
            'subscription_status' => 'active',
        ]);

        // A non-duplicate integrity failure during the renewal INSERT —
        // NOT unique-constraint — must surface as a 5xx so Paystack retries
        // instead of the renewal silently vanishing on a 200.
        DB::statement(
            'CREATE TRIGGER block_renewal BEFORE INSERT ON donations '
            ."WHEN NEW.reference LIKE 'ROI-REN-%' "
            ."BEGIN SELECT RAISE(ABORT, 'renewal insert blocked'); END"
        );

        $this->postSigned([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'renewal-rethrow-001',
                'amount' => 50000,
                'subscription' => [
                    'subscription_code' => 'SUB_RTH',
                    'email_token' => 'tok_rth',
                ],
            ],
        ])->assertStatus(500);

        $this->assertSame(0, Donation::where('subscription_code', 'SUB_RTH')->where('reference', 'like', 'ROI-REN-%')->count());
    }

    // ---------------------------------- late webhooks never resurrect a cancel

    public function test_late_invoice_update_does_not_resurrect_disabled_pledge(): void
    {
        Donation::create([
            'email' => 'resurrect@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-DEAD01',
            'status' => 'Completed',
            'subscription_code' => 'SUB_DEAD',
            'subscription_status' => 'disabled',
        ]);

        // Webhook order is not guaranteed — a paid invoice event landing AFTER
        // subscription.disable must not flip the cancelled parent back to active.
        $this->postSigned([
            'event' => 'invoice.update',
            'data' => [
                'invoice_code' => 'INV_DEAD',
                'paid' => true,
                'subscription' => ['subscription_code' => 'SUB_DEAD'],
                'next_payment_date' => '2027-03-01T00:00:00.000Z',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('donations', [
            'reference' => 'ROI-PAY-DEAD01',
            'subscription_status' => 'disabled',
            'next_payment_date' => '2027-03-01T00:00:00.000Z',
        ]);
    }

    // ------------------------------------------------------- manage link

    public function test_manage_endpoint_returns_and_caches_paystack_url(): void
    {
        Donation::create([
            'email' => 'manage@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-MANAGE01',
            'status' => 'Completed',
            'subscription_code' => 'SUB_MANAGE',
            'subscription_token' => 'tok_manage',
        ]);

        Http::fake([
            'https://api.paystack.co/subscription/*/manage/link' => Http::response([
                'status' => true,
                'message' => 'Link generated',
                'data' => ['url' => 'https://paystack.me/manage-sub-manage'],
            ], 200),
        ]);

        $this->getJson('/api/payments/subscription/ROI-PAY-MANAGE01/manage')
            ->assertOk()
            ->assertJsonPath('url', 'https://paystack.me/manage-sub-manage');

        $this->assertDatabaseHas('donations', [
            'reference' => 'ROI-PAY-MANAGE01',
            'subscription_manage_url' => 'https://paystack.me/manage-sub-manage',
        ]);

        // Second call serves the cached URL — no extra gateway round-trip.
        $this->getJson('/api/payments/subscription/ROI-PAY-MANAGE01/manage')
            ->assertOk()
            ->assertJsonPath('url', 'https://paystack.me/manage-sub-manage');
        Http::assertSentCount(1);
    }

    public function test_manage_endpoint_404_without_pledge_and_409_without_token(): void
    {
        Donation::create([
            'email' => 'nolink@test.ke',
            'amount' => 100,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'one-time',
            'reference' => 'ROI-PAY-NOPLEDGE',
            'status' => 'Completed',
        ]);

        $this->getJson('/api/payments/subscription/ROI-PAY-NOPLEDGE/manage')
            ->assertStatus(404)
            ->assertJsonPath('detail', 'No pledge is linked to this reference.');

        $this->getJson('/api/payments/subscription/ROI-PAY-DOESNOTEXIST/manage')
            ->assertStatus(404);

        Donation::create([
            'email' => 'notoken@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-NOTOKEN',
            'status' => 'Completed',
            'subscription_code' => 'SUB_NOTOKEN',
            'subscription_token' => null,
        ]);

        $this->getJson('/api/payments/subscription/ROI-PAY-NOTOKEN/manage')
            ->assertStatus(409);
    }

    // ------------------------------------------------- charge.success fast path

    public function test_charge_success_fast_path_links_subscription_by_reference(): void
    {
        Donation::create([
            'email' => 'fastpath@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-FASTPATH01',
            'status' => 'Pending Paystack Checkout',
        ]);

        $this->postSigned([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'ROI-PAY-FASTPATH01',
                'amount' => 50000,
                'subscription' => [
                    'subscription_code' => 'SUB_FAST',
                    'email_token' => 'tok_fast',
                    'next_payment_date' => '2026-11-01T00:00:00.000Z',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('donations', [
            'reference' => 'ROI-PAY-FASTPATH01',
            'status' => 'Completed',
            'subscription_code' => 'SUB_FAST',
            'subscription_token' => 'tok_fast',
            'subscription_status' => 'active',
            'next_payment_date' => '2026-11-01T00:00:00.000Z',
        ]);
    }

    public function test_charge_success_fast_path_ignores_one_time_donations(): void
    {
        Donation::create([
            'email' => 'onetime.fast@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'one-time',
            'reference' => 'ROI-PAY-OTFAST',
            'status' => 'Pending Paystack Checkout',
        ]);

        $this->postSigned([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'ROI-PAY-OTFAST',
                'amount' => 50000,
                'subscription' => [
                    'subscription_code' => 'SUB_OT',
                    'email_token' => 'tok_ot',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('donations', [
            'reference' => 'ROI-PAY-OTFAST',
            'status' => 'Completed',
            'subscription_code' => null,
            'subscription_token' => null,
        ]);
    }

    public function test_charge_success_fast_path_never_overwrites_an_existing_link(): void
    {
        Donation::create([
            'email' => 'already@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-EXISTING',
            'status' => 'Completed',
            'subscription_code' => 'SUB_ORIGINAL',
            'subscription_token' => 'tok_original',
        ]);

        $this->postSigned([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'ROI-PAY-EXISTING',
                'amount' => 50000,
                'subscription' => [
                    'subscription_code' => 'SUB_OTHER',
                    'email_token' => 'tok_other',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('donations', [
            'reference' => 'ROI-PAY-EXISTING',
            'subscription_code' => 'SUB_ORIGINAL',
            'subscription_token' => 'tok_original',
        ]);
    }

    // ---------------------------------------- m-1: lifecycle hits parent only

    public function test_lifecycle_disable_never_flips_renewal_children(): void
    {
        $parent = Donation::create([
            'email' => 'parent.only@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-PLIFE',
            'status' => 'Completed',
            'subscription_code' => 'SUB_PLIFE',
            'subscription_status' => 'active',
        ]);
        // Renewal child rows share subscription_code but are historical credits.
        $child = Donation::create([
            'email' => 'parent.only@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-REN-CHILD01',
            'status' => 'Completed',
            'subscription_code' => 'SUB_PLIFE',
            'subscription_status' => 'active',
        ]);

        $this->postSigned([
            'event' => 'subscription.disable',
            'data' => ['subscription_code' => 'SUB_PLIFE'],
        ])->assertOk();

        $this->assertSame('disabled', $parent->fresh()->subscription_status);
        $this->assertSame('active', $child->fresh()->subscription_status);
    }

    // ------------------------------------ M-4: matcher prefers Completed rows

    public function test_subscription_create_prefers_completed_row_over_newer_pending(): void
    {
        // Completed row is OLDER than the pending duplicate — the matcher must
        // still bind the mandate to the settled row.
        $completed = Donation::create([
            'email' => 'prefer.completed@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-DONE',
            'status' => 'Completed',
        ]);
        $pending = Donation::create([
            'email' => 'prefer.completed@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-PENDING',
            'status' => 'Pending Paystack Checkout',
        ]);
        Donation::where('id', $completed->id)->update(['created_at' => now()->subHours(3)]);
        Donation::where('id', $pending->id)->update(['created_at' => now()->subMinutes(5)]);

        $this->postSigned([
            'event' => 'subscription.create',
            'data' => [
                'subscription_code' => 'SUB_PREF',
                'email_token' => 'tok_pref',
                'status' => 'active',
                'amount' => 50000,
                'customer' => ['email' => 'prefer.completed@test.ke'],
                'plan' => ['plan_code' => 'PLN_X', 'currency' => 'KES'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('donations', [
            'reference' => 'ROI-PAY-DONE',
            'subscription_code' => 'SUB_PREF',
        ]);
        $this->assertNull($pending->fresh()->subscription_code);
    }

    // ---------------------------------------------------- verify payload

    public function test_sanitized_verify_exposes_subscription_state_without_secrets(): void
    {
        // Sandbox verify path: completes without a gateway round-trip.
        config(['roi.allow_dev_payment_bypasses' => true]);

        Donation::create([
            'email' => 'badge@test.ke',
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
            'frequency' => 'monthly',
            'reference' => 'ROI-PAY-BADGE01',
            'status' => 'Completed',
            'subscription_code' => 'SUB_BADGE',
            'subscription_token' => 'tok_badge_secret',
            'subscription_status' => 'active',
            'next_payment_date' => '2026-11-01T00:00:00.000Z',
        ]);

        $json = $this->getJson('/api/payments/verify/ROI-PAY-BADGE01')
            ->assertOk()
            ->assertJsonPath('sanitized', true)
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.next_payment_date', '2026-11-01T00:00:00.000Z')
            ->json();

        $this->assertArrayNotHasKey('subscription_token', $json['subscription']);
        $this->assertArrayNotHasKey('code', $json['subscription']);
        $this->assertArrayNotHasKey('subscription_manage_url', $json);
        $this->assertStringNotContainsString('tok_badge_secret', json_encode($json));
        $this->assertStringNotContainsString('https://', json_encode($json['subscription']));
    }
}
