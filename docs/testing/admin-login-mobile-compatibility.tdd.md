# Admin Login and Mobile Compatibility — TDD Evidence

## Source plan

[`plans/admin-login-and-mobile-compatibility.md`](../../plans/admin-login-and-mobile-compatibility.md)

## User journeys

- As the single administrator, I can sign in with email and password only and receive the same protected JWT session.
- As a visitor, I can always reach a Donate control without it leaving or disappearing from my viewport.
- As a phone or tablet user, I can navigate pages, use both donation methods, complete forms, and operate the admin dashboard without page-level horizontal scrolling.

## RED → GREEN evidence

| Task | RED evidence | GREEN evidence | Guarantee |
|---|---|---|---|
| Password-only backend login | `php artisan test --filter=test_login_succeeds_with_email_and_password_only` returned 422 instead of 200 while `mfa_code` was required | `php artisan test --filter=login` passed 11 tests / 31 assertions; full suite passed 139 tests / 677 assertions | Login accepts email/password without TOTP while throttling, generic errors, hashing, JWTs, and authorization regressions remain covered |
| Frontend login contract | Existing client sent `mfa_code` and rendered an MFA input | `npm test` passed 19/19, including request-body and generic-error tests | Browser login sends only `{ email, password }` and does not expose MFA-specific failure details |
| Donate and responsive layout | Live browser measurements showed no Donate CTA from 768–1023px and a 1218px right edge in a 1024px viewport | `npm run test:responsive` passed across six viewports and 13 routes plus authenticated admin dashboard | Donate remains visible/in bounds; shared pages, drawer, both donation tabs, ticket pages, and admin dashboard do not create page-level horizontal overflow |

## Verification commands

```text
cd laravel-backend && php artisan test
cd frontend && npm test
cd frontend && PLAYWRIGHT_EXECUTABLE_PATH=<local-chromium> npm run test:responsive
cd frontend && npm run build
git diff --check
```

## Coverage and known gaps

- The repository does not currently expose a configured line-coverage command, so no percentage is claimed.
- Browser coverage is behavior-focused: 320×640, 360×480, 375×667, 768×1024, 1024×768, and 1536×900.
- Payment submission is intentionally not exercised by responsive QA; the modal is inspected read-only through both payment-method tabs.
- The Playwright executable path is environment-specific locally. CI should install the package-matched Chromium build or set `PLAYWRIGHT_EXECUTABLE_PATH`.

