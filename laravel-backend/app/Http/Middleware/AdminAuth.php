<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    public function __construct(protected JwtService $jwt)
    {
    }

    /**
     * Ports backend.auth.get_current_admin:
     * - Missing/invalid token → 401 {"detail": "Could not validate global administrator credentials"}
     * - Token `sub` must exactly equal the configured single ADMIN_EMAIL
     * - Falls back to a synthetic global_admin identity when no DB row is seeded yet.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->cookie('roi_admin_token');

        if (!$token) {
            return $this->reject();
        }

        $payload = $this->jwt->decode($token);
        $email = is_array($payload) ? ($payload['sub'] ?? null) : null;

        if ($email === null || $email !== config('roi.admin_email')) {
            return $this->reject();
        }

        $admin = AdminUser::where('email', $email)->first();
        if (!$admin) {
            if ($email === config('roi.admin_email')) {
                $admin = (object) ['email' => $email, 'role' => 'global_admin'];
            } else {
                return $this->reject();
            }
        }

        $request->attributes->set('roi_admin', $admin);
        $request->attributes->set('roi_admin_email', is_object($admin) && property_exists($admin, 'email') ? $admin->email : null);

        return $next($request);
    }

    protected function reject(): Response
    {
        return response()->json(
            ['detail' => 'Could not validate global administrator credentials'],
            401,
            ['WWW-Authenticate' => 'Bearer']
        );
    }
}
