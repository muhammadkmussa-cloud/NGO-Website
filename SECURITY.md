# Security

Security posture for this repository — a **sanitised public showcase** of a full-stack community-platform build. Organisation details, contacts, registration numbers, imagery and media have been removed; every secret in this tree is a placeholder.

> This document describes the controls that ship in this code. It is not a report of a live production system, and it contains no operational detail from any real deployment.

---

## 1. Reporting a vulnerability

This repository is a portfolio artifact and is not a hosted service. If you find a genuine issue in the code, open a GitHub issue describing the problem (no exploit details required) so it can be triaged. Please do not test against systems you do not own.

---

## 2. Authentication and authorisation

| Control | Where |
|---|---|
| Password-only single-admin login; no MFA seed or fixed email is rendered in markup | `AuthController`, `frontend/src/js/pages/adminLogin.js` |
| Generic 401 responses (no per-stage enumeration oracle), route throttle + account/IP failure counters | `AuthController`, `AppServiceProvider` |
| Bearer JWT (HS256, algorithm pinned, `exp` enforced) guarding all 36 admin routes through one `roi.admin` middleware | `app/Http/Middleware/AdminAuth.php`, `routes/api.php` |
| Modern password hashing (bcrypt/Argon) with fail-closed boot guards against demo or legacy SHA-256 hashes | `PasswordService`, `AppServiceProvider::enforceProductionHardening()` |
| Client-side `#/admin` gating is presence-of-token only — a UX affordance; the backend is the authority | `frontend/src/js/storage.js` |

### Production boot guards

The application refuses to boot in `ENVIRONMENT=production` when:

- `JWT_SECRET_KEY` is missing, shorter than 32 characters, or a known template value;
- the configured admin password is a known demo hash;
- `APP_DEBUG` is enabled;
- `ALLOW_DEV_PAYMENT_BYPASSES` is set to true.

---

## 3. Payments and money movement

- **Server-authoritative pricing.** The client never supplies an amount the server trusts; totals are recomputed from the stored catalogue.
- **Payment kill switch.** `PAYMENTS_ENABLED=false` fails closed: donation and paid-ticket initiation, verification, both webhook paths and STK retry all return HTTP 503 without creating or reserving anything. Complimentary tickets remain available.
- **Dev/test sandbox bypasses** (`checkout.paystack.com/verified-sandbox-*`, unsigned webhooks, gateway-free completion) require an explicit `ALLOW_DEV_PAYMENT_BYPASSES` opt-in and are refused outright in production.
- **Paystack webhook** signature is verified (HMAC-SHA512 over the raw body against `PAYSTACK_SECRET_KEY`) whenever the secret is configured; replays and duplicates are acknowledged without side effects.
- **M-Pesa webhook** verifies a shared `MPESA_WEBHOOK_TOKEN` when configured and never mutates an unknown reference.
- **Inventory integrity** uses `lockForUpdate` inside the checkout transaction so concurrent buyers cannot oversell.
- **Order references** carry sufficient entropy; the reference-keyed endpoints that expose buyer details additionally require email confirmation.

---

## 4. Input handling and transport

- Validation on every public intake form (volunteer, contact, solutions inquiry) with per-route throttles.
- `hash_equals` on every shared-secret comparison.
- CSV export neutralises formula injection (`=`, `+`, `-`, `@` leading cells).
- Security headers middleware sets HSTS, `X-Frame-Options`, `X-Content-Type-Options`, referrer policy and a restrictive permissions policy.
- Error rendering returns FastAPI-compatible `{"detail": "..."}` bodies; `APP_DEBUG` is forced off in production.
- Request IDs are attached to every response and to log lines for correlation.

---

## 5. Frontend posture

- All `innerHTML` sinks route through an escape helper; the one unescaped interpolation identified in review (raw `/health` JSON) should be escaped if health payloads ever become attacker-influenced.
- Payment authorization URLs are opened only after the backend validates the gateway origin — treat any change here as security-sensitive.
- `?apiBase=` is accepted for split deployments; in a hardened build it should be a build-time constant only, with `Authorization` attached solely to `/admin/*` requests and a CSP meta tag added.
- The offline fallback engine never fabricates a real payment: in strict mode (`window.ROI_STRICT_API_MODE = true`) any backend failure throws instead of serving demo data.

---

## 6. Secrets and configuration

- `.env` is gitignored and never committed. Every committed environment template (`.env.example`, `laravel-backend/.env.example`, `deployment/cpanel/.env.production.example`) contains **placeholders only** — no live gateway keys, no channel IDs, no mail credentials.
- Placeholder paybills/shortcodes, admin email and JWT material are recognisably fake and are rejected by the production boot guards if left unchanged.
- The cPanel runbook keeps `.env` outside the document root with `600` permissions and forbids pasting secrets into Git or chat.
- `composer audit` runs clean; dependency lockfiles are committed.

---

## 7. Known limitations

These are deliberate or accepted trade-offs in a showcase build, not oversights:

1. **Demo mode default.** Without `window.ROI_STRICT_API_MODE = true` in the shipped HTML shell, the SPA falls back to an offline demo dataset on API failure. Always enable strict mode for any deployment that handles real money.
2. **Public cache-refresh flag.** `GET /api/youtube/channel-videos?refresh=1` can wipe the media cache and consume YouTube quota. Gate it behind admin auth or the cron secret before exposing it publicly.
3. **Reference-keyed endpoints.** `/pass`, `/verify`, `/resend` and the order lookup must stay email-gated; reference entropy must not be lowered.
4. **Token storage.** The admin JWT lives in `localStorage`; any XSS becomes full admin compromise. A CSP and an `httpOnly` cookie session are the right next steps.
5. **No upload surface.** There are no file-upload routes; if one is added, it needs content-type allowlisting and out-of-tree storage.

---

## 8. Verification

```bash
cd laravel-backend
composer audit
vendor/bin/pint --test
php artisan test

cd ../frontend
npm test
npm run build
```

Test-suite status, coverage and known gaps are documented in [`docs/testing/`](docs/testing/).
