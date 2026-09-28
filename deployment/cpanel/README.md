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
