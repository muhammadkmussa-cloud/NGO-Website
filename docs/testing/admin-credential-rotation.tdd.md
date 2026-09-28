# Admin Credential Rotation — TDD Evidence

## Source plan

- [`plans/admin-credential-rotation.md`](../../plans/admin-credential-rotation.md)

## User journeys

1. As an operator, I can generate a modern admin password hash through hidden, confirmed input so plaintext never enters source code or shell arguments.
2. As an operator, I can synchronize configuration to exactly one database administrator without supplying plaintext to the command.
3. As the administrator, I can log in with the rotated configured identity while the previous identity is rejected.
4. As the administrator, all sessions issued before a JWT-secret rotation become invalid, including rotations that keep the same email.

## Task report

### Credential commands

- RED: `php artisan test --filter=AdminCredentialCommandsTest`
- Evidence: 4 tests executed and failed because `roi:sync-admin` and `roi:hash-admin-password` did not exist.
- GREEN: the same target passed with 6 tests and 40 assertions after implementation and review hardening.
- Guarantee: hidden password collection, strength validation, modern hash generation, fail-closed configuration validation, transactional reconciliation, idempotence, and one-admin cleanup.

### Rotation/session behavior

- Initial run: `php artisan test --filter=AdminCredentialRotationTest` found one fixture mismatch because PHPUnit's cost-4 bcrypt hash was correctly rejected by the production-grade cost floor.
- Corrected fixture: the same target passed with 3 tests and 17 assertions using a cost-12 test hash.
- Guarantee: new configured credentials are authoritative, the previous email is rejected, protected routes accept the new token, JWT-secret rotation invalidates old tokens, and production cookies remain HTTP-only, Secure, SameSite=Lax, and logout-expiring.

### Full regression

- Command: `composer test`
- Result: PASS — 148 tests, 734 assertions.
- Command: `vendor/bin/pint --test app/Console/Commands/SyncAdminCommand.php app/Console/Commands/HashAdminPasswordCommand.php tests/Feature/AdminCredentialCommandsTest.php tests/Feature/AdminCredentialRotationTest.php`
- Result: PASS.
- Command: `composer audit --format=plain`
- Result: PASS — no security vulnerability advisories.
- Command: `git diff --check`
- Result: PASS.

## Test specification

| # | What is guaranteed | Test target | Type | Result |
|---|---|---|---|---|
| 1 | Sync leaves exactly one configured administrator and is idempotent | `AdminCredentialCommandsTest` | Integration | PASS |
| 2 | Invalid email, malformed/legacy hash, and unsafe hash parameters abort without mutation | `AdminCredentialCommandsTest` | Security integration | PASS |
| 3 | Password input is hidden, confirmed, strength-checked, and never echoed | `AdminCredentialCommandsTest` | Security integration | PASS |
| 4 | Weak-password override is local-only and explicitly requested | `AdminCredentialCommandsTest` | Security integration | PASS |
| 5 | Rotated credentials authenticate and the old identity fails generically | `AdminCredentialRotationTest` | API integration | PASS |
| 6 | JWT-key rotation invalidates a previously valid token | `AdminCredentialRotationTest` | API integration | PASS |
| 7 | Login/logout cookies retain production security attributes | `AdminCredentialRotationTest` | API integration | PASS |
| 8 | Existing backend behavior remains intact | `composer test` | Regression | PASS |

## Coverage and known gaps

- The repository does not currently provide a PHP code-coverage script/driver, so no percentage was claimed.
- Production secret-manager mutation was not performed from this workspace. It requires a new undisclosed strong password and access to the deployment platform.
- The password disclosed in conversation is intentionally not included in this report, source, tests, or command history.
- TDD checkpoint commits were not created because the working tree already contained extensive user changes; committing at intermediate gates risked capturing unrelated work.
