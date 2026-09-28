<?php

namespace App\Services;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Throwable;

class JwtService
{
    /**
     * Issues an HS256 token with claims identical to the FastAPI implementation:
     * {sub: admin email, role: global_admin, exp: now + ACCESS_TOKEN_EXPIRE_MINUTES}.
     */
    public function issueToken(string $email, string $role = 'global_admin'): string
    {
        $ttl = (int) config('roi.access_token_expire_minutes', 480);

        $payload = [
            'sub' => $email,
            'role' => $role,
            'exp' => Carbon::now('UTC')->addMinutes($ttl)->getTimestamp(),
        ];

        return JWT::encode($payload, (string) config('roi.jwt_secret_key'), (string) config('roi.jwt_algorithm', 'HS256'));
    }

    /**
     * Decodes and validates a token; returns the payload array or null on any failure
     * (mirrors catching jose.JWTError).
     */
    public function decode(string $token): ?array
    {
        try {
            $payload = JWT::decode($token, new Key((string) config('roi.jwt_secret_key'), (string) config('roi.jwt_algorithm', 'HS256')));

            return json_decode(json_encode($payload), true);
        } catch (ExpiredException $e) {
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
