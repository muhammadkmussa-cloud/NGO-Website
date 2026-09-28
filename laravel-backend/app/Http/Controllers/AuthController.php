<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Services\AuditLogger;
use App\Services\JwtService;
use App\Services\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    /** Brute-force ceiling for password guessing, per IP+email, sliding 15 min. */
    private const PASSWORD_MAX_ATTEMPTS = 10;

    private const PASSWORD_DECAY_MINUTES = 15;

    public function __construct(
        protected PasswordService $passwords,
        protected JwtService $jwt,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * POST /api/auth/login
     * M-4: uniform failure surface — every rejection returns the identical
     * generic message until repeated failures trip the same per-IP+email
     * lockout. Distinct stage details remain in the audit log (admin-only visibility).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
        ], [
            'email.email' => 'value is not a valid email address',
        ]);

        $email = strtolower(trim($data['email']));
        $configuredEmail = strtolower((string) config('roi.admin_email'));
        $configuredPasswordHash = (string) config('roi.admin_password_hash');
        $genericReject = response()
            ->json(['detail' => 'Invalid administrator credentials.'], 401);

        // Keep a dedicated password-guessing cap in addition to the route-level
        // limiter so repeated attempts against the single admin slot stay bounded.
        $pwdKeys = $this->passwordAttemptKeys($request, $email);
        if ($this->hasTooManyPasswordAttempts($pwdKeys)) {
            return response()->json(
                ['detail' => 'Too many failed attempts. Try again later.'],
                429,
                ['Retry-After' => (string) (self::PASSWORD_DECAY_MINUTES * 60)]
            );
        }

        $admin = AdminUser::where('email', config('roi.admin_email'))->first();

        if ($email !== $configuredEmail) {
            // Keep the public failure surface closer to the configured-email path.
            $this->passwords->verify($data['password'], $configuredPasswordHash);
            $this->incrementPasswordAttempts($pwdKeys);
            $this->audit->record($data['email'], 'login failed', 'Unauthorized email attempt');

            return $genericReject;
        }

        $passwordOk = $this->passwords->verify($data['password'], $configuredPasswordHash);

        if (!$passwordOk) {
            $this->incrementPasswordAttempts($pwdKeys);
            $this->audit->record($data['email'], 'login failed', 'Invalid password provided');

            return $genericReject;
        }

        $this->clearPasswordAttempts($pwdKeys);
        $this->audit->record($data['email'], 'login succeeded', 'Guarded JWT session token issued');

        if ($admin) {
            // ADMIN_PASSWORD_HASH is authoritative; sync the database row only
            // after the configured password succeeds so env rotation is immediate.
            if ($this->passwords->needsRehash((string) $admin->password_hash)
                || !$this->passwords->verify($data['password'], (string) $admin->password_hash)) {
                $admin->password_hash = $this->passwords->hashForStorage($data['password']);
            }
            $admin->last_login = Carbon::now('UTC');
            $admin->save();
        }

        $token = $this->jwt->issueToken((string) config('roi.admin_email'));
        $secure = strtolower((string) config('roi.environment')) === 'production';

        // Defense-in-depth: also issue the JWT as an httpOnly cookie so it cannot
        // be read by JavaScript (XSS) on same-origin production deployments. The
        // SPA still sends the bearer header cross-origin/dev; the cookie rides
        // automatically where same-origin.
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
        ])->cookie(
            'roi_admin_token',
            $token,
            (int) config('roi.access_token_expire_minutes', 480),
            '/',
            null,
            $secure,
            true,   // httpOnly
            false,  // raw
            'lax'
        );
    }

    private function passwordAttemptKeys(Request $request, string $email): array
    {
        return [
            'pwd-attempts:' . sha1(($request->ip() ?: 'unknown') . '|' . $email),
            'pwd-account-attempts:' . sha1($email),
        ];
    }

    private function hasTooManyPasswordAttempts(array $keys): bool
    {
        foreach ($keys as $key) {
            if ((int) Cache::get($key, 0) >= self::PASSWORD_MAX_ATTEMPTS) {
                return true;
            }
        }

        return false;
    }

    private function incrementPasswordAttempts(array $keys): void
    {
        foreach ($keys as $key) {
            Cache::add($key, 0, now()->addMinutes(self::PASSWORD_DECAY_MINUTES));
            Cache::increment($key);
        }
    }

    private function clearPasswordAttempts(array $keys): void
    {
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * POST /api/auth/logout — clears the httpOnly session cookie. Public so it
     * works even after the token has expired client-side.
     */
    public function logout(): JsonResponse
    {
        $secure = strtolower((string) config('roi.environment')) === 'production';

        return response()->json(['detail' => 'Logged out.'])
            ->cookie('roi_admin_token', '', -1, '/', null, $secure, true, false, 'lax');
    }
}
