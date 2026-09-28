<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCredentialRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_rotated_email_and_password_are_authoritative_and_old_identity_fails(): void
    {
        $newEmail = 'rotation-admin@example.test';
        $newPassword = 'Rotation-test-password-1!';

        AdminUser::create([
            'email' => 'old-admin@example.test',
            'password_hash' => Hash::make('Old-rotation-password-2!'),
        ]);

        config([
            'roi.admin_email' => $newEmail,
            'roi.admin_password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);

        $this->artisan('roi:sync-admin')->assertSuccessful();

        $this->postJson('/api/auth/login', [
            'email' => 'old-admin@example.test',
            'password' => 'Old-rotation-password-2!',
        ])->assertUnauthorized()
            ->assertJsonPath('detail', 'Invalid administrator credentials.');

        $login = $this->postJson('/api/auth/login', [
            'email' => $newEmail,
            'password' => $newPassword,
        ])->assertOk()->assertJsonStructure(['access_token']);

        $this->withHeader('Authorization', 'Bearer '.$login->json('access_token'))
            ->getJson('/api/admin/stats')
            ->assertOk();
    }

    public function test_rotating_jwt_secret_invalidates_existing_token_even_when_email_is_unchanged(): void
    {
        config([
            'roi.admin_email' => 'stable-admin@example.test',
            'roi.jwt_secret_key' => base64_encode(random_bytes(32)),
        ]);

        $token = app(JwtService::class)->issueToken('stable-admin@example.test');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/stats')
            ->assertOk();

        config(['roi.jwt_secret_key' => base64_encode(random_bytes(32))]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/stats')
            ->assertUnauthorized()
            ->assertJsonPath('detail', 'Could not validate global administrator credentials');
    }

    public function test_login_and_logout_use_http_only_production_cookies(): void
    {
        $email = 'cookie-admin@example.test';
        $password = 'Cookie-test-password-3!';
        config([
            'roi.environment' => 'production',
            'roi.admin_email' => $email,
            'roi.admin_password_hash' => Hash::make($password),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ])->assertOk();

        $loginCookie = collect($login->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'roi_admin_token');

        $this->assertNotNull($loginCookie);
        $this->assertTrue($loginCookie->isHttpOnly());
        $this->assertTrue($loginCookie->isSecure());
        $this->assertSame('lax', $loginCookie->getSameSite());

        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertCookieExpired('roi_admin_token');
    }
}
