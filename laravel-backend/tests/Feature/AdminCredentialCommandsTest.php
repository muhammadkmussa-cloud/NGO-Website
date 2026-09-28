<?php

namespace Tests\Feature;

use App\Console\Commands\HashAdminPasswordCommand;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class AdminCredentialCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_admin_reconciles_the_database_to_one_configured_admin(): void
    {
        AdminUser::create([
            'email' => 'old-admin@example.test',
            'password_hash' => Hash::make('Old-local-password-1!'),
        ]);

        $configuredHash = $this->modernBcryptHash('New-local-password-2!');
        config([
            'roi.admin_email' => 'configured-admin@example.test',
            'roi.admin_password_hash' => $configuredHash,
        ]);

        $this->artisan('roi:sync-admin')
            ->expectsOutput('Administrator credentials synchronized.')
            ->assertSuccessful();

        $this->assertSame(1, AdminUser::count());
        $this->assertDatabaseMissing('admin_users', ['email' => 'old-admin@example.test']);
        $this->assertDatabaseHas('admin_users', [
            'email' => 'configured-admin@example.test',
            'password_hash' => $configuredHash,
        ]);

        $this->artisan('roi:sync-admin')->assertSuccessful();
        $this->assertSame(1, AdminUser::count());
    }

    public function test_sync_admin_rejects_invalid_configuration_without_mutating_data(): void
    {
        $existingHash = Hash::make('Existing-local-password-1!');
        AdminUser::create([
            'email' => 'existing-admin@example.test',
            'password_hash' => $existingHash,
        ]);

        foreach ([
            ['new-admin@example.test', ''],
            ['not-an-email', $this->modernBcryptHash('Valid-local-password-2!')],
            ['new-admin@example.test', hash('sha256', 'Legacy-local-password-3!')],
            ['new-admin@example.test', 'not-a-password-hash'],
            ['new-admin@example.test', '$2y$00$'.str_repeat('a', 53)],
            ['new-admin@example.test', '$2y$12$'.str_repeat('a', 53)],
            ['new-admin@example.test', password_hash('weak-argon', PASSWORD_ARGON2ID, [
                'memory_cost' => 1024,
                'time_cost' => 1,
                'threads' => 1,
            ])],
        ] as [$email, $hash]) {
            config(['roi.admin_email' => $email, 'roi.admin_password_hash' => $hash]);

            $this->artisan('roi:sync-admin')->assertFailed();

            $this->assertSame(1, AdminUser::count());
            $this->assertDatabaseHas('admin_users', [
                'email' => 'existing-admin@example.test',
                'password_hash' => $existingHash,
            ]);
        }
    }

    public function test_hash_admin_password_uses_confirmed_hidden_input_and_outputs_only_a_modern_hash(): void
    {
        $plain = 'Temporary-local-password-4!';

        $tester = $this->passwordHashCommandTester();
        $tester->setInputs([$plain, $plain]);
        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString($plain, $output);
        $this->assertMatchesRegularExpression('/\$(2y|argon2(?:i|id))\$/', $output);
        preg_match('/\$2y\$[0-9]{2}\$[\.\/A-Za-z0-9]{53}|\$argon2(?:i|id)\$[^\r\n]+/', $output, $matches);
        $this->assertNotEmpty($matches[0] ?? null);
        $this->assertTrue(Hash::check($plain, $matches[0]));
    }

    public function test_hash_admin_password_rejects_weak_or_mismatched_input(): void
    {
        $weak = $this->passwordHashCommandTester();
        $weak->setInputs(['too-short', 'too-short']);
        $this->assertSame(1, $weak->execute([]));

        $mismatch = $this->passwordHashCommandTester();
        $mismatch->setInputs([
            'Temporary-local-password-5!',
            'Different-local-password-6!',
        ]);
        $this->assertSame(1, $mismatch->execute([]));
    }

    public function test_hash_admin_password_disables_visible_prompt_fallback(): void
    {
        $plain = 'Temporary-local-password-7!';
        $command = new class($plain) extends HashAdminPasswordCommand
        {
            public array $fallbacks = [];

            public function __construct(private string $plain)
            {
                parent::__construct();
            }

            public function secret($question, $fallback = true)
            {
                $this->fallbacks[] = $fallback;

                return $this->plain;
            }
        };
        $command->setLaravel($this->app);

        $tester = new CommandTester($command);

        $this->assertSame(0, $tester->execute([]));
        $this->assertSame([false, false], $command->fallbacks);
    }

    public function test_hash_admin_password_allows_weak_password_only_for_explicit_local_use(): void
    {
        config(['roi.environment' => 'development']);

        $local = $this->passwordHashCommandTester();
        $local->setInputs(['Short1!', 'Short1!']);
        $this->assertSame(0, $local->execute(['--allow-weak-local' => true]));
        $this->assertStringContainsString('local temporary use only', $local->getDisplay());

        config(['roi.environment' => 'production']);

        $production = $this->passwordHashCommandTester();
        $production->setInputs(['Short1!', 'Short1!']);
        $this->assertSame(1, $production->execute(['--allow-weak-local' => true]));
    }

    private function passwordHashCommandTester(): CommandTester
    {
        $command = $this->app->make(HashAdminPasswordCommand::class);
        $command->setLaravel($this->app);

        return new CommandTester($command);
    }

    private function modernBcryptHash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
