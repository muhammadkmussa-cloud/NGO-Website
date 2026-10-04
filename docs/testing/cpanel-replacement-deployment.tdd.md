# cPanel Replacement Deployment — Verification Evidence

> Historical verification evidence for one work item, recorded when the effort landed. Command names and test targets are still valid; assertion counts reflect that run. Current suite status is reported in the [repository README](../../README.md#8-testing).

The implementation followed a replacement plan with four constraints: an empty MySQL launch, payments disabled on day one, a recoverable backup of whatever occupied the document root, and no permanent deletion during cutover. The runbook lives at [`deployment/cpanel/README.md`](../../deployment/cpanel/README.md).

## RED / GREEN evidence

### Payment kill switch

- RED: `php artisan test --filter=PaymentsDisabledTest` initially ran with intended failures because checkout, paybill disclosure, paid-ticket checkout, and retry were not guarded.
- Reviewer-driven RED additions exposed unguarded payment verification, both webhook paths, and ticket-order verification.
- GREEN: `PaymentsDisabledTest` passes.
- Guarantees: disabled mode withholds payment details; freezes donation checkout, paid-ticket checkout, both verification paths, both webhooks, and STK retry; creates no new records; reserves no inventory; complimentary tickets remain available.

### Coming-soon donation UI

- RED: the responsive test timed out because the payment form remained visible after the API reported `enabled: false`.
- GREEN: the browser suite passes across all configured phone, tablet, laptop, and desktop viewports.
- Guarantees: Donate remains visible; disabled mode shows contact links without forms, gateway controls, paybill details, or copy actions; enabled-mode escaping/XSS coverage remains active.

### Deployment tooling

- Adversarial review found the first release archive policy could include local logs or alternate environment files.
- The builder was changed to an explicit production allowlist with a second forbidden-artifact scan.
- Shell syntax, Composer metadata, archive checksum, required archive paths, and forbidden archive paths were verified.

## Final verification

| Check | Command / basis |
|---|---|
| Backend suite | `php artisan test` |
| Frontend unit suite | `npm test` |
| Responsive Playwright suite | `npm run test:responsive` |
| Composer audit | `composer audit` |
| npm audit | `npm audit` |
| PHP lint | `vendor/bin/pint --test` + `php -l` on new files |
| Shell syntax | `bash -n` on `deployment/cpanel/*.sh` |
| Whitespace | `git diff --check` |
| Release archive | SHA-256 checksum + forbidden-artifact scan |

## Release output and external gate

`build-release.sh` emits `artifacts/<name>.tar.gz` plus a matching `.sha256`. The archive is never committed.

Cutting over requires temporary FTPS/cPanel access and a strong administrator password delivered through a secure channel: backup and verification, MySQL creation, secret installation, staging, document-root cutover, live acceptance checks, and a rollback rehearsal. None of that is performed from a local checkout.
