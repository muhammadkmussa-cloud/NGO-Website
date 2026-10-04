# Laravel backend — DEMO platform API

REST API for the showcase platform in this repository: health/readiness, password-only admin auth, public content, ticketing, donations and monthly pledges (Paystack + M-Pesa/Daraja), YouTube media sync, and the single-admin console.

See the [repository README](../README.md) for the full architecture, endpoint surface, quickstart and deployment notes, and [`SECURITY.md`](../SECURITY.md) for the security posture.

## Requirements

- PHP 8.2+ (8.3 recommended)
- Composer 2
- SQLite (default) or PostgreSQL/MySQL
- Extensions: `bcmath`, `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_sqlite` (or `pdo_pgsql`/`pdo_mysql`), `tokenizer`, `xml`, `zip`

`deployment/cpanel/preflight.sh` checks all of these.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve --port=8000
```

The API listens on `/api/*`. In single-origin mode the built SPA is copied into `public/`, so the same server also serves `/`.

## Configuration

Settings live in [`config/roi.php`](config/roi.php) and are read from the environment. The defaults are safe for local development; the values that matter in production:

| Variable | Notes |
|---|---|
| `ENVIRONMENT` | `development` / `test` enable sandbox behaviour. Production fails closed on every payment bypass. |
| `ALLOW_DEV_PAYMENT_BYPASSES` | Must be unset or false in production — the app refuses to boot otherwise. |
| `PAYMENTS_ENABLED` | Operational kill switch for all paid transactions. |
| `JWT_SECRET_KEY` | Must be ≥32 characters and not a known template value, or production refuses to boot. |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD_HASH` | The single administrator. Only a bcrypt/Argon hash belongs in the environment. |

## Common commands

```bash
php artisan migrate --seed      # schema + idempotent demo content
php artisan test                # feature suite
vendor/bin/pint --test          # style
composer audit                  # dependency advisories

php artisan roi:hash-admin-password   # hidden prompt → modern password hash
php artisan roi:sync-admin            # reconcile to exactly one configured admin
php artisan roi:youtube-sync          # media cache refresh
php artisan schedule:run              # scheduler entry point (cron every minute)
```

## Testing

```bash
php artisan migrate
php artisan test
```

Current suite status and known gaps are documented in [`../README.md`](../README.md#8-testing) and [`../docs/testing/`](../docs/testing/).

## Layout

```text
app/
├── Console/Commands/       # roi:* scheduler and admin tooling
├── Http/
│   ├── Controllers/        # Auth, Public, Admin, Payment, Media, Ticket, Pledge
│   ├── Middleware/         # AdminAuth (JWT bearer), SecurityHeaders, RequestId, TrustProxies
│   └── Requests/           # FormRequest validation
├── Models/                 # Eloquent models + ApiSerializable concern
├── Services/               # Jwt, Password, Audit, Paystack, Mpesa, YouTubeSync, Ticket, Pledge
└── Providers/              # boot guards, rate limiters
config/roi.php              # all platform settings
database/{migrations,seeders,factories}
routes/{api.php,web.php}
resources/views/            # ticket pass + pledge pay page (server-rendered)
```
