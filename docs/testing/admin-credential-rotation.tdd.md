# Admin Credential Rotation — TDD Evidence

> Historical test evidence for one work item, recorded when the effort landed. Command names and test targets are still valid; assertion counts reflect that run. Current suite status is reported in the [repository README](../../README.md#8-testing).

## User journeys

1. As an operator, I can generate a modern admin password hash through hidden, confirmed input so plaintext never enters source code or shell arguments.
2. As an operator, I can synchronise configuration to exactly one database administrator without supplying plaintext to the command.
3. As the administrator, I can log in with the rotated configured identity while the previous identity is rejected.
4. As the administrator, all sessions issued before a JWT-secret rotation become invalid, including rotations that keep the same email.

## Task report

### Credential commands

- RED: `php artisan test --filter=AdminCredentialCommandsTest`
- Evidence: the target failed first because `roi:sync-admin` and `roi:hash-admin-password` did not exist.
- GREEN: the same target passed after implementation and review hardening.
- Guarantee: hidden password collection, strength validation, modern hash generation, fail-closed configuration validation, transactional reconciliation, idempotence, and one-admin cleanup.

### Rotation / session behaviour

- Initial run found one fixture mismatch because PHPUnit's cost-4 bcrypt hash was correctly rejected by the production-grade cost floor.
- Corrected fixture: the target passed using a cost-12 test hash.
- Guarantee: new configured credentials are authoritative, the previous email is rejected, protected routes accept the new token, JWT-secret rotation invalidates old tokens, and production cookies remain HTTP-only, Secure, SameSite=Lax, and logout-expiring.

### Regression commands

```bash
composer test
vendor/bin/pint --test app/Console/Commands/SyncAdminCommand.php \
  app/Console/Commands/HashAdminPasswordCommand.php \
  tests/Feature/AdminCredentialCommandsTest.php \
  tests/Feature/AdminCredentialRotationTest.php
composer audit --format=plain
git diff --check
```

## Test specification

| # | What is guaranteed | Test target | Type |
|---|---|---|---|
| 1 | Sync leaves exactly one configured administrator and is idempotent | `AdminCredentialCommandsTest` | Integration |
| 2 | Invalid email, malformed/legacy hash, and unsafe hash parameters abort without mutation | `AdminCredentialCommandsTest` | Security integration |
| 3 | Password input is hidden, confirmed, strength-checked, and never echoed | `AdminCredentialCommandsTest` | Security integration |
| 4 | Weak-password override is local-only and explicitly requested | `AdminCredentialCommandsTest` | Security integration |
| 5 | Rotated credentials authenticate and the old identity fails generically | `AdminCredentialRotationTest` | API integration |
| 6 | JWT-key rotation invalidates a previously valid token | `AdminCredentialRotationTest` | API integration |
| 7 | Login/logout cookies retain production security attributes | `AdminCredentialRotationTest` | API integration |
| 8 | Existing backend behaviour remains intact | `composer test` | Regression |

## Coverage and known gaps

- The repository does not provide a PHP code-coverage script/driver, so no percentage is claimed.
- Production secret-manager mutation cannot be performed from a local checkout; it requires a strong password and deployment-platform access.
- No password or hash appears anywhere in this report, the source, the tests, or command history.
- Intermediate TDD checkpoint commits were not created because the working tree already contained unrelated in-flight changes.
