<?php

namespace App\Providers;

use App\Services\PasswordService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * JWT secrets that must never reach production (F-01).
     */
    protected const FORBIDDEN_SECRET_FRAGMENTS = [
        'change-in-production',
        'your_256bit_hex_secret_here',
        'super-secret',
        'secret_key_here',
        'changeme',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->enforceJwtSecretStrength();
        $this->enforceProductionHardening();

        // Sliding-window parity note: Laravel uses a fixed window per minute.
        // Matches FastAPI RateLimitMiddleware defaults (120 requests / 60s / client IP).
        // The /api/health route is registered outside the throttle group.
        RateLimiter::for('api', function (Request $request) {
            // Health check exempt, matching FastAPI's exempt_paths.
            if ($request->is('api/health') || $request->is('api/ready') || $request->is('favicon.ico')) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by($request->ip() ?: '127.0.0.1');
        });

        // M-1: STK re-dispatch triggers a real SMS prompt at the buyer's phone.
        // Cap per order reference (not just per IP) so distributed callers cannot
        // harass one buyer, and per IP so references cannot be sprayed.
        // NOTE: these are NAMED limiters deliberately — Laravel's unnamed
        // throttle:N,1 shares a single domain|IP bucket across every route,
        // which would let traffic on one endpoint starve another's budget.
        RateLimiter::for('stk-retry', function (Request $request) {
            return [
                Limit::perHour(5)->by('ref:'.($request->route('reference') ?: '')),
                Limit::perMinute(6)->by('stk-ip:'.($request->ip() ?: '127.0.0.1')),
            ];
        });

        RateLimiter::for('payments-checkout', function (Request $request) {
            return Limit::perMinute(10)->by('pay-ck:'.($request->ip() ?: '127.0.0.1'));
        });

        RateLimiter::for('payments-verify', function (Request $request) {
            return Limit::perMinute(30)->by('pay-vf:'.($request->ip() ?: '127.0.0.1'));
        });

        // M-2: the manage-link endpoint mints hosted card-update/cancel URLs —
        // give it its own budget so verify traffic can't starve it (and vice
        // versa), capped per reference so one pledge can't be probed at scale.
        RateLimiter::for('payments-manage', function (Request $request) {
            return [
                Limit::perMinute(10)->by('pay-mg:'.($request->route('reference') ?: '')),
                Limit::perMinute(20)->by('pay-mg-ip:'.($request->ip() ?: '127.0.0.1')),
            ];
        });

        RateLimiter::for('ticket-orders', function (Request $request) {
            return Limit::perMinute(30)->by('ord:'.($request->ip() ?: '127.0.0.1'));
        });

        // Monthly pledge collection: creation dispatches live STK prompts and
        // pay retries can too — tight per-IP budgets (spec §18).
        RateLimiter::for('pledges-create', function (Request $request) {
            return Limit::perMinute(5)->by('pled-new:'.($request->ip() ?: '127.0.0.1'));
        });

        RateLimiter::for('pledges-pay', function (Request $request) {
            return [
                Limit::perMinute(6)->by('pled-pay:'.($request->route('token') ?: '')),
                Limit::perMinute(20)->by('pled-pay-ip:'.($request->ip() ?: '127.0.0.1')),
            ];
        });

        // The payment PAGE is read-only and must never starve POST /pay or the
        // status poll — separate buckets (spec §7/§10). Keyed per token, so it
        // needs its own per-IP backstop: otherwise every distinct bogus token
        // gets a fresh 20/min budget (the web group has no global throttle).
        RateLimiter::for('pledges-pay-page', function (Request $request) {
            return [
                Limit::perMinute(20)->by('pled-pg:'.($request->route('token') ?: '')),
                Limit::perMinute(60)->by('pled-pg-ip:'.($request->ip() ?: '127.0.0.1')),
            ];
        });

        // §10 polling: the pay page may poll every 5s for a bounded window —
        // give status its own per-token budget so polling can't 429 the POST.
        RateLimiter::for('pledges-pay-status', function (Request $request) {
            return [
                Limit::perMinute(20)->by('pled-st:'.($request->route('token') ?: '')),
                Limit::perMinute(60)->by('pled-st-ip:'.($request->ip() ?: '127.0.0.1')),
            ];
        });

        RateLimiter::for('ticket-lookup', function (Request $request) {
            return Limit::perMinute(20)->by('lookup:'.($request->ip() ?: '127.0.0.1'));
        });

        // M-1 follow-up: convert every remaining unnamed throttle:N,1 to a named
        // limiter — unnamed buckets share one domain|IP counter per app, letting
        // traffic on one public form starve admin login or ticket checkout.
        RateLimiter::for('auth-login', fn (Request $r) => Limit::perMinute(5)->by('login:'.($r->ip() ?: '127.0.0.1')));
        RateLimiter::for('public-form', fn (Request $r) => Limit::perMinute(20)->by('form:'.($r->ip() ?: '127.0.0.1')));
        RateLimiter::for('solution-inquiry', fn (Request $r) => Limit::perMinute(10)->by('inquiry:'.($r->ip() ?: '127.0.0.1')));
        RateLimiter::for('ticket-checkout', fn (Request $r) => Limit::perMinute(20)->by('tck-co:'.($r->ip() ?: '127.0.0.1')));
        RateLimiter::for('ticket-recover', fn (Request $r) => Limit::perMinute(8)->by('tck-rec:'.($r->ip() ?: '127.0.0.1')));
        RateLimiter::for('ticket-lookup-order', fn (Request $r) => Limit::perMinute(20)->by('tck-lo:'.($r->ip() ?: '127.0.0.1')));
    }

    /**
     * Spec 12: refuse production boot when debug is on or the seeded demo
     * password hash is still configured.
     */
    protected function enforceProductionHardening(): void
    {
        $environment = strtolower((string) config('roi.environment'));
        if ($environment !== 'production') {
            $this->warnLiveKeysOutsideProduction();

            return;
        }

        // M-2: dev conveniences must be structurally impossible in production.
        if ((bool) config('roi.allow_dev_payment_bypasses')) {
            throw new \RuntimeException(
                'ALLOW_DEV_PAYMENT_BYPASSES=true is forbidden in production — refusing to boot. '
                .'This flag enables unsigned webhooks and gateway-free payment completion.'
            );
        }

        if ((bool) config('app.debug')) {
            throw new \RuntimeException('APP_DEBUG must be false in production — refusing to boot.');
        }

        $demoHash = hash('sha256', 'admin123');
        $adminPasswordHash = (string) config('roi.admin_password_hash');
        if (hash_equals($demoHash, $adminPasswordHash)) {
            throw new \RuntimeException(
                'ADMIN_PASSWORD_HASH still matches the seeded demo password. Rotate it before production deploy.'
            );
        }

        if (app(PasswordService::class)->verify('admin123', $adminPasswordHash)) {
            throw new \RuntimeException(
                'ADMIN_PASSWORD_HASH still verifies the seeded demo password. Rotate it before production deploy.'
            );
        }

        if (preg_match('/\A[a-f0-9]{64}\z/i', $adminPasswordHash) === 1) {
            throw new \RuntimeException(
                'ADMIN_PASSWORD_HASH must use a modern password hash in production. Generate it with Laravel Hash::make.'
            );
        }
    }

    /**
     * M-2: live payment credentials outside production almost always mean
     * ENVIRONMENT is misconfigured — which used to silently enable unsigned
     * webhooks and gateway-free completion. Warn loudly.
     */
    protected function warnLiveKeysOutsideProduction(): void
    {
        $paystackLive = str_starts_with((string) config('roi.paystack_secret_key'), 'sk_live');
        $darajaLive = (string) config('roi.mpesa_passkey') !== '';

        if (! $paystackLive && ! $darajaLive) {
            return;
        }

        try {
            logger()->warning(
                'ROI security: live gateway credentials are configured while ENVIRONMENT is not "production". '
                .'Verify this host is not customer-facing, or rotate the environment.'
            );
        } catch (Throwable $e) {
            // Never block local boot on logging failures.
        }
    }

    /**
     * F-01: a forgeable JWT secret means complete admin compromise. Hard-fail in
     * production when the secret is missing, short, or a known template value;
     * warn loudly in local development so weak secrets never silently ship.
     */
    protected function enforceJwtSecretStrength(): void
    {
        $secret = (string) config('roi.jwt_secret_key');
        $environment = strtolower((string) config('roi.environment'));
        $weak = strlen($secret) < 32
            || collect(self::FORBIDDEN_SECRET_FRAGMENTS)->contains(
                fn (string $fragment) => $fragment !== '' && str_contains(strtolower($secret), $fragment)
            );

        if (! $weak) {
            return;
        }

        if ($environment === 'production') {
            throw new \RuntimeException(
                'JWT_SECRET_KEY is missing, too short (<32 chars), or a known template value. '
                .'Generate one with: base64_encode(random_bytes(32)) — refusing to boot.'
            );
        }

        try {
            logger()->warning('ROI security: JWT_SECRET_KEY is weak or a template value — rotate before production deploy.');
        } catch (Throwable $e) {
            // Never block local boot on logging failures.
        }
    }
}
