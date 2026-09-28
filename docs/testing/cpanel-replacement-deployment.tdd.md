# cPanel Replacement Deployment — Verification Evidence

## Source plan

The implementation follows the approved cPanel replacement plan for `reachingoutinitiative.org`: empty MySQL launch, payments disabled, recoverable WordPress backup, and no permanent deletion during cutover.

## RED/GREEN evidence

### Payment kill switch

- RED: `php artisan test --filter=PaymentsDisabledTest` initially ran 5 tests with 4 intended failures because checkout, Paybill disclosure, paid-ticket checkout, and retry were not guarded.
- Reviewer-driven RED additions exposed unguarded payment verification, both webhook paths, and ticket-order verification.
- GREEN: `PaymentsDisabledTest` passes 9 tests and 35 assertions.
- Guarantees: disabled mode withholds payment details; freezes donation checkout, paid-ticket checkout, both verification paths, both webhooks, and STK retry; creates no new records; reserves no inventory; complimentary tickets remain available.

### Coming-soon donation UI

- RED: the responsive test timed out because the payment form remained visible after the API reported `enabled: false`.
- GREEN: the browser suite passes across all configured phone, tablet, laptop, and desktop viewports.
- Guarantees: Donate remains visible; disabled mode shows contact links without forms, gateway controls, Paybill details, or copy actions; enabled-mode escaping/XSS coverage remains active.

### Deployment tooling

- Adversarial review found the first release archive policy could include local logs or alternate environment files.
- The builder was changed to an explicit production allowlist with a second forbidden-artifact scan.
- Shell syntax, Composer metadata, archive checksum, required archive paths, and forbidden archive paths were verified.

## Final verification

| Check | Result |
|---|---|
| Backend suite | PASS — 157 tests, 769 assertions |
| Frontend unit suite | PASS — 19 tests |
| Responsive Playwright suite | PASS |
| Composer audit | PASS — no advisories |
| npm audit | PASS — 0 vulnerabilities |
| New PHP files/config Pint check | PASS |
| PHP syntax checks | PASS |
| Shell syntax checks | PASS |
| `git diff --check` | PASS |
| Release SHA-256 | PASS |
| Forbidden release artifacts | PASS — none found |

## Release output and remaining external gate

- Artifact: `artifacts/roi-cpanel-20260922T202402Z.tar.gz`
- Checksum: `artifacts/roi-cpanel-20260922T202402Z.tar.gz.sha256`
- cPanel was not mutated. Backup, MySQL creation, secret installation, staging, document-root cutover, live verification, and rollback rehearsal require temporary FTPS/cPanel access and a new strong administrator password delivered through a secure channel.
