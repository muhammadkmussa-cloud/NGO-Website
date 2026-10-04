# cPanel deployment runbook

Target: `https://reachingoutinitiative.org`  
Launch mode: empty MySQL database, payments disabled, recoverable WordPress replacement.

## Safety rules

- Use FTPS, not plaintext FTP. If FTPS is unavailable, upload through cPanel File Manager over HTTPS.
- Never paste cPanel, FTP, database, or application secrets into Git or chat.
- Do not delete `public_html`, the WordPress database, email accounts, DNS records, or SSL certificates.
- Do not cut over unless the file archive and WordPress SQL export have both been verified.
- Keep the WordPress backup and quarantined files for 30 days.

## 1. Preflight and backup

1. In **cPanel → Domains**, record the exact document root for `reachingoutinitiative.org`.
2. Upload this directory outside that document root and run `bash preflight.sh` in cPanel Terminal.
3. If WP-CLI is installed, run:

   ```bash
   bash backup-wordpress.sh /exact/current/docroot /exact/private/backup-directory
   ```

   Otherwise create both a home-directory backup and MySQL export with cPanel Backup Wizard.
4. Import the SQL export into a temporary verification database and confirm the WordPress tables exist. Remove only the temporary verification database afterward.
5. Move the current document-root contents into a timestamped quarantine directory outside the document root. Use File Manager’s move operation so the change is recoverable; do not permanently delete them.

## 2. Build and upload

On the development machine:

```bash
bash deployment/cpanel/build-release.sh
```

Upload the generated `.tar.gz` and `.sha256` through FTPS. On cPanel, run `sha256sum -c <archive>.sha256` before extraction.

Create a dedicated MySQL database and user through **MySQL Database Wizard**. Grant that user privileges only on the new database. Do not reuse the WordPress database.

Copy `.env.production.example` to a private cPanel path outside the document root, replace every placeholder, and set file permissions to `600`. Generate the admin hash locally with the hidden command:

```bash
cd laravel-backend
php artisan roi:hash-admin-password
```

Then stage the release in cPanel Terminal:

```bash
bash stage-release.sh /exact/upload/roi-cpanel-UTC.tar.gz /exact/private/roi/releases /exact/private/roi/shared/.env CONFIRM_STAGE
```

This runs Composer, empty-schema migrations, the single-admin synchronization, storage linking, and Laravel optimization. It does not change the live document root.

## 3. Cutover

Preferred layout:

1. In **cPanel → Domains**, point `reachingoutinitiative.org` to the staged release’s `public/` directory.
2. Preserve the existing AutoSSL certificate and the `www` redirect.
3. In **Cron Jobs**, add the server’s PHP 8.3 binary and exact Artisan path:

   ```cron
   * * * * * /usr/local/bin/php /exact/private/roi/releases/RELEASE/artisan schedule:run >> /dev/null 2>&1
   ```

If cPanel will not change the primary-domain document root, keep the Laravel application outside `public_html`, copy only the staged `public/` contents into the emptied document root, and adjust its `index.php` maintenance, autoload, and bootstrap paths to the absolute release directory. Never expose `.env`, `vendor/`, `storage/`, or application source below `public_html`.

## 4. Acceptance checks

- `GET /api/health` returns HTTP 200 and `status: online`.
- `GET /api/ready` returns HTTP 200 and `status: ready`.
- `/`, `/checking`, and the admin login load over HTTPS without mixed content.
- `www.reachingoutinitiative.org` redirects to the apex domain.
- Public content endpoints return empty collections, not seeded demo data.
- The configured administrator can log in and access `/api/admin/stats`; the former identity cannot.
- Donate opens the coming-soon contact modal on phone and desktop.
- Paybill details are absent and all payment initiation, verification, callback, and retry routes return HTTP 503 without mutations.
- Security headers are present and Laravel logs contain no new 500 errors.

## 5. Rollback

If readiness, assets, admin authentication, or error-rate checks fail:

1. Restore the former document-root setting or move the quarantined WordPress files back with File Manager.
2. Restore the WordPress database configuration if it was changed; the plan does not drop that database.
3. Remove the new cron entry and keep the failed Laravel release for diagnosis.
4. Recheck the WordPress homepage, admin login, HTTPS certificate, and `www` redirect.

Permanently remove the old WordPress files and database only after 30 stable days and a separate explicit approval.

## 6. Monthly pledges (M-Pesa)

### How monthly pledges work

1. The Donate modal's **Monthly** frequency in KES shows a `Card | Mobile Money` selector. USD monthly stays card-only.
2. **Card** → the existing Paystack subscription checkout, unchanged. Manage/cancel happens on Paystack's hosted link from the success page.
3. **Mobile Money** → `POST /api/pledges` creates the pledge plus its first monthly obligation and dispatches the first M-Pesa request through Paystack's `/charge` API. The supporter approves the M-Pesa prompt; the Paystack webhook marks the month `PAID`, advances the next-payment anchor, sends the confirmation email and stops that month's reminders.
4. The scheduler owns the monthly loop from then on: each morning at 09:00 Africa/Nairobi missing obligations are backfilled and, on the due day, a fresh M-Pesa request goes out. Unpaid months age through `DUE → MISSED` and receive reminder emails at `PLEDGE_REMINDER_DAYS` after the due date.
5. Reminder emails carry a personal `/pledges/pay/{token}` link (HMAC-signed, bound to amount + period, TTL `PLEDGE_PAYMENT_LINK_TTL_HOURS`). The pay page re-sends the request, lets the supporter correct their phone number, and offers **Cancel my monthly pledge**: cancelling stops all future obligations, prompts and reminders while every historical row stays intact.
6. A month can never be double-`PAID` (`UNIQUE (pledge_id, billing_month)`), and no scheduler or webhook path can flip an unpaid month to `PAID` without a verified gateway amount + currency match.

### Card vs M-Pesa behaviour

| | Card (subscription) | M-Pesa (monthly pledge) |
|---|---|---|
| Charge | Paystack saved-card subscription | Paystack `/charge` M-Pesa request |
| Monthly billing | Paystack renewal webhook | `roi:pledges-bill` due-day request |
| Reminders | none (silent renewal) | `roi:pledges-remind` emails at d+1/3/7 |
| Manage / cancel | Paystack hosted manage link | Cancel button on the secure pay page |
| Webhook references | donation / subscription refs | `ROI-PLE-*` |

Pledge STK prompts go exclusively through Paystack. The Daraja `MpesaService` is only used to normalize phone numbers for pledges; its legacy STK/webhook paths (tickets) are untouched. Stored phones stay canonical `2547XXXXXXXX` (Daraja form); `PaystackService` adds the leading `+` at the gateway boundary because Paystack's M-Pesa charge rejects the bare `254…` form with `Invalid phone number format`.

### Scheduler / cron (production)

One cPanel cron entry (already listed in §3) drives everything:

```cron
* * * * * /usr/local/bin/php /exact/private/roi/releases/RELEASE/artisan schedule:run >> /dev/null 2>&1
```

Production reality (DirectAdmin/cPanel): the live entry targets the `current`
symlink, so a release cutover needs **no** cron edit and there is still exactly
one scheduler line:

```cron
* * * * * /usr/local/php83/bin/php /home/USER/roi/current/artisan schedule:run >> /home/USER/roi/shared/schedule.log 2>&1
```

Laravel's scheduler then runs these on `Africa/Nairobi`, regardless of server timezone:

- `roi:pledges-bill` — daily at 09:00: backfill obligations, send the due-day M-Pesa request, retire past unpaid months to `MISSED`.
- `roi:pledges-remind` — hourly at :05: sweep expired prompts back to `DUE`, send offset reminder emails.
- `roi:youtube-sync` — every six hours (pre-existing).

Both pledge commands are idempotent (unique obligations + email ledger), so a duplicate run, overlap or manual re-run never double-charges or double-emails:

```bash
php artisan roi:pledges-bill     # safe to run manually
php artisan roi:pledges-remind   # safe to run manually
```

### Reminder system

- `PLEDGE_REMINDER_DAYS` (default `1,3,7`) = days **after** the due date; each offset fires exactly once per payment, enforced by `UNIQUE (pledge_payment_id, kind, reminder_key)`.
- Eligible: `ACTIVE` + M-Pesa pledges whose month is `DUE`/`FAILED`/`MISSED`. Skipped: `PAID` months, `PAUSED`/`CANCELLED` pledges, card pledges.
- Batched `PLEDGE_EMAIL_BATCH_SIZE` per offset per run; `reminder_count` and `last_reminder_at` are updated on the payment and every send is written to the audit log.
- Email failures are recorded on the ledger and never thrown — a SMTP outage cannot stall the scheduler or fail a settlement webhook.
- No SMS integration (plan decision A4); the channel-shaped sender leaves room to add one later.

### Webhook setup

Paystack dashboard → Settings → Webhooks:

```
https://reachingoutinitiative.org/api/payments/webhook/paystack
```

The endpoint enforces the HMAC-SHA512 `x-paystack-signature` against `PAYSTACK_SECRET_KEY` on every delivery. `charge.success` / `charge.failed` for `ROI-PLE-*` references settle pledge months (amount + currency verified server-side first); replays and duplicates are acknowledged without side effects. No separate webhook-secret variable is required, so none was added.

### Required environment variables

All in the private `.env` (permissions `600`, never in Git):

```
PAYMENTS_ENABLED=false      # flip to true in step 5 below, after §4 acceptance
PAYSTACK_SECRET_KEY=sk_live_...
PLEDGE_REMINDER_DAYS=1,3,7
PLEDGE_EMAIL_BATCH_SIZE=25
PLEDGE_TIMEZONE=Africa/Nairobi
PLEDGE_PAYMENT_LINK_TTL_HOURS=168
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=465            # or 587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=ssl      # or tls
MAIL_FROM_ADDRESS=...
MAIL_FROM_NAME=...
```

- `MAIL_HOST` must match the server's TLS certificate CN (on a DirectAdmin box that is typically the server hostname, e.g. `web1.example.co.ke`). Pointing STARTTLS at `localhost` fails with a certificate CN mismatch even though the port is open.
- `APP_URL` must be the canonical `https://` origin — pay links are absolute.
- `JWT_SECRET_KEY` (or `APP_KEY`) keys the pay-link HMAC; link minting fails loudly without it.
- Reminder mail reuses the existing cPanel `MAIL_*` block (plan decision A3) — no new provider, no `PAYSTACK_WEBHOOK_SECRET`, no SMS variables. `MPESA_WEBHOOK_TOKEN` belongs to the legacy Daraja webhook, not pledges.

### How payment retries work

- 60-second cooldown: a double-click reuses the in-flight prompt instead of sending a second M-Pesa request.
- Attempts older than 15 minutes are swept to `expired` and their `PENDING` month returns to `DUE`, so the pay link always retries cleanly with a **new** reference.
- Phone corrections on the pay page are capped at 2 per hour, so a leaked link cannot become an STK-spam cannon.
- Already-paid months answer `409` and never start a new attempt; the client can never reprice a month (amounts are read from the token-bound database row).
- Gateway refusal or `charge.failed` leaves the month `DUE`/`FAILED` and audited; a later retry starts fresh.

### Testing locally

```bash
cd laravel-backend && php artisan test          # 281 tests
cd frontend && node --test scripts/ticketing.test.mjs   # 31 tests
```

No test reaches a real gateway or mailbox: `/charge` is `Http::fake`d behind `Http::preventStrayRequests`, webhooks are posted with a locally computed HMAC, and mail runs on `MAIL_MAILER=array` (or `Mail::fake`). For a manual end-to-end pass use Paystack **test** keys with `PAYMENTS_ENABLED=true` and a small KES amount, then cancel from the pay page to exercise §17.

### Deploying the pledge feature

1. Upload the release, run migrations (or apply `laravel-backend/database/production/002_pledge_tables.sql` directly on MySQL), then `php artisan optimize`. Uploaded media lives in a **shared** `roi/shared/storage/app/public`; `stage-release.sh` symlinks each release's `storage/app/public` to it, so media survives every cutover — never copy a release's empty `storage/app/public` over the shared one.
2. Set the environment block above in the private `.env` (`600`) and `php artisan config:cache`.
3. Confirm the single `schedule:run` cron from §3 exists — never add one cron per command.
4. Set the Paystack webhook URL. A dashboard test event now returns HTTP 503 (payments still disabled) — that is expected; re-send the test event after step 5 and expect HTTP 200.
5. Flip `PAYMENTS_ENABLED=true` only when ready — the §4 acceptance checks intentionally assume payments start disabled. Enabling it activates both the endpoints and the scheduled pledge commands.
6. Smoke-test: small M-Pesa pledge → approve prompt → `PAID` + confirmation email; open the pay link → cancel → confirm no further bill/reminder run touches it; check `/api/admin/audit-logs` for obligation / initiated / settled / cancelled rows.
7. Rollback: `PAYMENTS_ENABLED=false` immediately 503s pledge store/pay and the Paystack webhook (no new settlements), and both scheduled commands (`roi:pledges-bill`, `roi:pledges-remind`) exit early — no new M-Pesa requests or reminder emails leave the system, so no cron surgery or schema rollback is needed. Set it back to `true` to resume; the bill backfill catches up automatically on the next run.
