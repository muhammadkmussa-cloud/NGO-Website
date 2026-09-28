<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncAdminCommand extends Command
{
    protected $signature = 'roi:sync-admin';

    protected $description = 'Synchronize the database to the configured single administrator';

    public function handle(): int
    {
        $email = trim((string) config('roi.admin_email'));
        $passwordHash = (string) config('roi.admin_password_hash');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('ADMIN_EMAIL must be a valid email address.');

            return self::FAILURE;
        }

        if (! $this->isModernPasswordHash($passwordHash)) {
            $this->error('ADMIN_PASSWORD_HASH must be a modern bcrypt or Argon hash.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $passwordHash): void {
            $admin = AdminUser::query()->updateOrCreate(
                ['email' => $email],
                ['password_hash' => $passwordHash],
            );

            AdminUser::query()->whereKeyNot($admin->getKey())->delete();
        });

        $this->info('Administrator credentials synchronized.');

        return self::SUCCESS;
    }

    private function isModernPasswordHash(string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        $info = password_get_info($hash);
        $algorithm = $info['algoName'] ?? 'unknown';

        return match ($algorithm) {
            'bcrypt' => $this->isUsableBcryptHash($hash, $info),
            'argon2i', 'argon2id' => $this->isUsableArgonHash($hash, $info),
            default => false,
        };
    }

    /**
     * Accept generated bcrypt hashes with sane work factors; reject placeholders,
     * malformed values, and unsafe costs before replacing the only admin row.
     */
    private function isUsableBcryptHash(string $hash, array $info): bool
    {
        if (preg_match('/^\$2[ayb]\$(\d{2})\$[\.\/A-Za-z0-9]{53}$/', $hash, $matches) !== 1) {
            return false;
        }

        if (! $this->hasEnoughHashCharacterVariety(substr($hash, 7))) {
            return false;
        }

        $cost = (int) ($info['options']['cost'] ?? $matches[1]);

        return $cost >= 10 && $cost <= 14;
    }

    private function isUsableArgonHash(string $hash, array $info): bool
    {
        if (preg_match('/^\$argon2(?:i|id)\$v=\d+\$m=\d+,t=\d+,p=\d+\$([A-Za-z0-9+\/]+={0,2})\$([A-Za-z0-9+\/]+={0,2})$/', $hash, $matches) !== 1) {
            return false;
        }

        if (! $this->hasEnoughHashCharacterVariety($matches[1].$matches[2])) {
            return false;
        }

        $options = $info['options'] ?? [];

        return (int) ($options['memory_cost'] ?? 0) >= 65536
            && (int) ($options['time_cost'] ?? 0) >= 2
            && (int) ($options['threads'] ?? 0) >= 1;
    }

    private function hasEnoughHashCharacterVariety(string $hashPayload): bool
    {
        return count(array_unique(str_split($hashPayload))) >= 8;
    }
}
