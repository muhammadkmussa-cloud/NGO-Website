# Demo NGO (DEMO) — Digital Platform

A dependency-light, full-stack digital platform for a community organisation: public marketing site, events and ticketing, monthly giving, a digital-solutions studio, and a single-admin console.

> This repository is a **sanitised public showcase**. Organisation details, contacts, registration numbers, brand imagery and the project film have been removed and replaced with generic placeholders. It is intended to demonstrate architecture and engineering practice, not to represent a real organisation.

---

## 1. What's in the box

* **Public site** — hash-routed vanilla-JS SPA: home, about, events, blog, media hub, digital solutions, portfolio, volunteer and contact.
* **Giving** — one-off donations and **monthly pledges** with renewal references, across Paystack and M-Pesa (Daraja STK Push).
* **Ticketing** — ticket types, QR codes, gate check-in with undo, order recovery, email caps and a public lookup portal.
* **Media engine** — YouTube Data API v3 sync with local relational caching and a quota-guard, driven by the Laravel scheduler.
* **Admin console** — single-admin, password-only, rate-limited and audited; CRUD for content, events, ticket types and volunteers, plus CSV export.
* **Resilience** — the SPA runs strictly against the API in production, or falls back to an offline demo dataset for previews.

---

## 2. Tech stack

| Layer | Choice |
|---|---|
| Frontend | Vanilla HTML/CSS/JS SPA — no framework runtime. Hash routing, Tailwind CSS compiled via CLI |
| Backend | Laravel 13 (PHP 8.2+) REST API — Eloquent ORM, JWT bearer auth (HS256), 120 req/min throttle |
| Database | SQLite (dev) / PostgreSQL (Supabase-ready via `supabase_schema.sql` or Laravel migrations) |
| Payments | Paystack (init / verify / HMAC-SHA512 webhooks) + Safaricom Daraja M-Pesa STK Push with a hardened webhook token |
| Media | YouTube Data API v3 sync + Laravel scheduler (`roi:youtube-sync`, every 6 h) |
| Hosting | Single VPS or shared host — Laravel serves both the API (`/api/*`) and the compiled SPA (`/`) from one origin, so no CORS is needed in production |

---

## 3. Quickstart

### 3.1 One-click launch

```bash
./start.sh     # macOS / Linux
start.bat      # Windows
```

Installs dependencies, runs migrations + seeder, starts the Laravel API on `:8000`, builds the SPA, and serves it on `:3000`.

**Single-origin mode:** the build also deploys the SPA into `laravel-backend/public/`, so `http://127.0.0.1:8000/` serves the whole platform from one origin.

### 3.2 Manual commands

```bash
# Backend
cd laravel-backend
composer install
cp .env.example .env          # then fill in secrets
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed

php artisan serve --port=8000

# Frontend
cd frontend
npm install
npm run build                 # Tailwind CLI + module assembly → dist/ (+ laravel-backend/public/)
npm run dev                   # static preview on :3000
```

### 3.3 Docker

```bash
docker compose up --build
# API: http://localhost:8000 · SPA preview: http://localhost:3000
```

### 3.4 Admin credentials

| Field | Configuration |
|---|---|
| Email | `ADMIN_EMAIL` (deployment template: `admin@example.com`) |
| Password | A unique 16+ character password; only its bcrypt/Argon hash is stored in `ADMIN_PASSWORD_HASH` |

The admin console is reachable via the footer link **Singular Admin Console**, or the secret header **logo 5-clicks-in-3-seconds** easter egg. Generate a hash safely with `php artisan roi:hash-admin-password`, place it in the environment's encrypted secret store, rotate `JWT_SECRET_KEY`, clear cached configuration, and run `php artisan roi:sync-admin`. No production password or hash belongs in Git.

---

## 4. Architecture

```text
NGO-Website/
├── laravel-backend/               # Laravel 13 REST API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/       # Auth, Public, Admin, Payment, Media controllers
│   │   │   └── Middleware/        # AdminAuth (JWT bearer guard)
│   │   ├── Models/                # 9 Eloquent models + ApiSerializable concern
│   │   └── Services/              # Jwt, Password, Audit, Paystack, Mpesa, YouTubeSync
│   ├── bootstrap/app.php          # {detail:"..."} error parity, 120/min throttle
│   ├── config/roi.php             # All DEMO env settings
│   ├── database/
│   │   ├── migrations/            # 9 tables
│   │   └── seeders/RoiSeeder.php  # Idempotent demo content (events, blogs, media, ...)
│   ├── routes/api.php             # Full endpoint surface (unchanged paths)
│   └── Dockerfile
├── frontend/                      # Vanilla SPA (no framework runtime)
│   ├── index.html                 # Shell (Inter fonts, favicon, runtime config)
│   ├── src/css/app.css            # Tailwind entry + custom utilities/keyframes
│   ├── src/js/
│   │   ├── main.js router.js api.js storage.js store.js ui.js icons.js
│   │   ├── data/fallbackData.js   # Offline demo dataset
│   │   ├── components/            # header, footer, donationModal, videoLightbox
│   │   └── pages/                 # home, about, events, blog, mediaHub,
│   │                              # contact, volunteer, adminLogin, adminDashboard
│   ├── scripts/build.mjs          # Assembles dist/ + deploys into Laravel public/
│   └── tailwind.config.js
├── supabase_schema.sql            # Postgres DDL (still valid for Supabase)
├── docker-compose.yml
├── start.sh / start.bat
└── .env.example
```

---

## 5. API surface

All routes are prefixed `/api` and render FastAPI-compatible errors (`{"detail": "..."}`).

| Domain | Endpoints |
|---|---|
| Health | `GET /api/health`, `GET /api/ready` (rate-limit exempt; production health omits engine internals) |
| Auth | `POST /api/auth/login`, `POST /api/auth/logout` |
| Public | `GET /public/blog[/{slug}]`, `/public/events`, `/public/media[/latest|/refresh]`, `/public/metrics`, `/public/leaders`, `/public/solutions[/{slug}]`, `POST /public/solutions/inquire`, `GET /public/portfolio[/{slug}]`, `POST /public/volunteer`, `POST /public/contact` |
| Payments | `POST /payments/checkout`, `GET /payments/verify/{ref}`, `POST /payments/webhook/mpesa` *(token-hardened)*, `POST /payments/webhook/paystack`, `GET /payments/paybills` |
| YouTube | `POST /youtube/cron-sync`, `GET /youtube/channel-videos` |
| Ticketing | `GET /public/events/{id}/tickets`, `POST /tickets/checkout`, `POST /tickets/recover`, `POST /tickets/lookup-order`, `GET /tickets/portal/{token}`, `GET /tickets/orders/{ref}[/verify|/pass]`, `POST /tickets/orders/{ref}/stk-retry`, `POST /tickets/orders/{ref}/resend`, `GET /tickets/lookup/{code}` |
| Admin (JWT) | `GET /admin/stats`, `/admin/audit-logs`, `/admin/volunteers[/export]`, `/admin/inquiries`, `PUT /admin/inquiries/{id}/read`, `POST /admin/media/sync`, CRUD: `/admin/blog`, `/admin/events`, `/admin/leaders`, `/admin/ticket-types`, `GET /admin/ticket-stats`, `GET /admin/ticket-orders[/export]`, `GET /admin/tickets/{code}`, `POST /admin/tickets/{code}/check-in`, `POST /admin/tickets/{code}/undo-check-in` |

### 5.1 Behavioural notes

* Login uses a fixed admin email plus password only. Rejections stay generic, successful configured-password logins upgrade/sync the stored hash, and password guessing is capped by both route throttling and account/IP failure counters.
* Dashboard FX display math (USD×130, EUR×140, GBP×165; KES floor 254,500) and vanity metric floors (120/24/95/45) are preserved.
* Dev/test sandbox payment stubs are preserved (`checkout.paystack.com/verified-sandbox-*`, `ws_CO_SIM_*`).
* Rate limiting is 120 req/min/IP (Laravel fixed window; a documented deviation from the legacy sliding window).
* Blog slug uniqueness is enforced on update as well as create.

### 5.2 Security hardening (intentional deviations)

1. Admin login is password-only, rate-limited, audited, and production-hardened against demo or legacy SHA-256 password hashes.
2. The M-Pesa webhook verifies a shared `MPESA_WEBHOOK_TOKEN` when configured and never mutates unknown references.
3. Paystack webhook HMAC-SHA512 is enforced whenever `PAYSTACK_SECRET_KEY` is set (dev bypass only with no secret configured).

See [`SECURITY.md`](SECURITY.md) for the full security posture.

---

## 6. Frontend resilience engine

`frontend/src/js/api.js` supports two modes:

* **Live production** (`window.ROI_STRICT_API_MODE = true`): any backend failure throws immediately.
* **Offline preview** (default): failures dispatch `roi_api_fallback_triggered`, render the amber transparency banner with **Retry Connection**, and serve the static demo dataset from `data/fallbackData.js`.

Runtime configuration lives in `frontend/index.html`:

```js
window.ROI_API_BASE_URL = '/api';        // override for split deployments
window.ROI_STRICT_API_MODE = true;       // enforce strict outage errors
```

---

## 7. Deployment (cPanel / shared hosting)

The production target requires PHP 8.3+, Composer, MySQL, and the PHP extensions listed by `deployment/cpanel/preflight.sh`. Build the SPA locally, keep the Laravel application and `.env` outside the public document root, and expose only `laravel-backend/public/`.

For the replacement workflow, release builder, server preflight, backup verification, empty-database migration, cutover and rollback instructions, see [`deployment/cpanel/README.md`](deployment/cpanel/README.md).

Run Laravel's scheduler every minute; the application itself dispatches YouTube synchronisation every six hours:

```cron
* * * * * /path/to/php /absolute/path/to/artisan schedule:run >> /dev/null 2>&1
```

---

## 8. Testing

```bash
cd laravel-backend
composer install
php artisan migrate --seed
php artisan test                    # 282 tests, all passing

# Frontend helpers
cd ../frontend
npm install
npm test                            # Node unit helpers (32 cases)
npm run test:responsive             # Playwright layout/DOM suite
npm run build
```

The suites cover health/readiness, password-only auth flow, public content endpoints, admin CRUD + CSV export, payment sandbox checkout/verify, both webhooks (including hardened token/signature rejection), YouTube quota-guard, ISO-8601 duration parsing, ticketing, digital solutions, production hardening (request ids, HSTS, webhook fail-closed, end-to-end checkout), and responsive frontend smoke coverage for Donate/header/modal behaviour across phone, tablet, small-laptop and desktop-nav breakpoints.

**Demo fixtures.** `php artisan migrate --seed` loads generic placeholder content — events, ticket types, blog posts, media catalogue, portfolio items, digital solutions, volunteers, donations and inquiries. Nothing in the seed data identifies a real organisation, venue, contact or channel, and the suite is green against it. Replace the fixtures in `laravel-backend/database/seeders/` with your own content before using this as a starting point for a live site.

Working notes for individual test efforts live in [`docs/testing/`](docs/testing/).

---

## 9. Licence & disclaimer

Provided as-is for portfolio and reference purposes. All organisation-specific content, contacts, imagery and media in this repository are placeholders.
