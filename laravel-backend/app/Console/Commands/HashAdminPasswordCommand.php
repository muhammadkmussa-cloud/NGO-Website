<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class HashAdminPasswordCommand extends Command
{
    protected $signature = 'roi:hash-admin-password {--allow-weak-local : Allow a weak temporary password outside production only}';

    protected $description = 'Generate a modern administrator password hash using hidden input';

    public function handle(): int
    {
        try {
            $password = (string) $this->secret('Enter the new administrator password', false);
            $confirmation = (string) $this->secret('Confirm the new administrator password', false);
        } catch (RuntimeException $e) {
            $this->error('Hidden password input is required.');

            return self::FAILURE;
        }

        if (! hash_equals($password, $confirmation)) {
            $this->error('The password confirmation does not match.');

            return self::FAILURE;
        }

        if (! $this->isStrongEnough($password)) {
            if ($this->runsInProduction() || ! $this->option('allow-weak-local')) {
                $this->error('Use at least 16 characters with upper-case, lower-case, number, and symbol characters.');

                return self::FAILURE;
            }

            $this->warn('Weak password accepted for local temporary use only. Do not deploy this credential.');
        }

        $this->line(Hash::make($password));

        return self::SUCCESS;
    }

    private function runsInProduction(): bool
    {
        return $this->laravel->environment('production')
            || strtolower((string) config('roi.environment')) === 'production';
    }

    private function isStrongEnough(string $password): bool
    {
        return strlen($password) >= 16
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/\d/', $password) === 1
            && preg_match('/[^a-zA-Z0-9]/', $password) === 1;
    }
}
