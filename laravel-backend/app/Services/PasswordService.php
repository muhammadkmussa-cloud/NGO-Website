<?php

namespace App\Services;

use Illuminate\Support\Facades\Hash;

class PasswordService
{
    /**
     * M-4: bcrypt/argon2 first (Laravel Hash facade), with the legacy unsalted
     * SHA-256 hexdigest as a transitional fallback. Legacy matches are upgraded
     * to bcrypt transparently on login (AuthController rehash-on-login).
     */
    public function verify(string $plain, string $hashed): bool
    {
        if ($hashed === '') {
            return false;
        }

        try {
            if (Hash::check($plain, $hashed)) {
                return true;
            }
        } catch (\Throwable $e) {
            // Not a bcrypt/argon string — fall through to legacy comparison.
        }

        if (password_verify($plain, $hashed)) {
            return true;
        }

        return hash_equals(strtolower($hashed), hash('sha256', $plain));
    }

    /** True when the stored hash is not a modern password algorithm. */
    public function needsRehash(string $hashed): bool
    {
        if ($hashed === '') {
            return false;
        }

        try {
            return Hash::needsRehash($hashed);
        } catch (\Throwable $e) {
            // Unparseable hash strings (raw SHA-256 hex) always need rehash.
            return true;
        }
    }

    /** Modern one-way hash for storage (bcrypt via Laravel defaults). */
    public function hashForStorage(string $plain): string
    {
        return Hash::make($plain);
    }

    /**
     * Legacy seeding scheme kept for ADMIN_PASSWORD_HASH env compatibility.
     * New deployments should store bcrypt hashes instead.
     */
    public function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
