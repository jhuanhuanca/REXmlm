<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth;

use App\Models\User;
use App\Modules\Auth\Services\TwoFactorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null, 'billing.offline' => true]);
    }

    public function test_admin_login_requires_two_factor_setup(): void
    {
        $admin = $this->admin();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('two_factor_status', 'setup');

        $token = $login->json('token');

        $this->withToken($token)->getJson('/api/v1/admin/users')->assertForbidden()
            ->assertJsonPath('code', 'two_factor_setup_required');
    }

    public function test_admin_confirms_totp_and_receives_full_session(): void
    {
        $admin = $this->admin();
        $twoFactor = app(TwoFactorService::class);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk();

        $pending = $login->json('token');

        $setup = $this->withToken($pending)->postJson('/api/v1/auth/two-factor/setup')
            ->assertOk();

        $secret = $setup->json('secret');
        $this->assertNotEmpty($secret);

        $confirm = $this->withToken($pending)->postJson('/api/v1/auth/two-factor/confirm', [
            'code' => $twoFactor->currentOtp($secret),
        ])->assertOk()
            ->assertJsonPath('two_factor_status', 'ok')
            ->assertJsonCount(8, 'recovery_codes');

        $this->withToken($confirm->json('token'))->getJson('/api/v1/admin/users')->assertOk();
    }

    public function test_admin_with_two_factor_must_pass_challenge(): void
    {
        $admin = $this->admin();
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->beginSetup($admin)['secret'];
        $twoFactor->confirmSetup($admin, $twoFactor->currentOtp($secret));

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('two_factor_status', 'challenge');

        $pending = $login->json('token');

        $this->withToken($pending)->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_required');

        $this->withToken($pending)->postJson('/api/v1/auth/two-factor/challenge', [
            'code' => '000000',
        ])->assertUnprocessable();

        $done = $this->withToken($pending)->postJson('/api/v1/auth/two-factor/challenge', [
            'code' => $twoFactor->currentOtp($secret),
        ])->assertOk();

        $this->withToken($done->json('token'))->getJson('/api/v1/admin/users')->assertOk();
    }

    public function test_leader_login_does_not_require_two_factor(): void
    {
        $leader = User::factory()->create(['email' => 'leader-2fa@test.com']);
        $leader->assignRole('leader');

        $this->postJson('/api/v1/auth/login', [
            'email' => $leader->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('two_factor_status', 'ok');
    }

    public function test_admin_cannot_disable_two_factor(): void
    {
        $admin = $this->admin();
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->beginSetup($admin)['secret'];
        $twoFactor->confirmSetup($admin, $twoFactor->currentOtp($secret));

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk();

        $challenge = $this->withToken($login->json('token'))->postJson('/api/v1/auth/two-factor/challenge', [
            'code' => $twoFactor->currentOtp($secret),
        ])->assertOk();

        $this->withToken($challenge->json('token'))->postJson('/api/v1/auth/two-factor/disable', [
            'code' => $twoFactor->currentOtp($secret),
        ])->assertForbidden();
    }

    private function admin(): User
    {
        $user = User::factory()->create([
            'email' => 'admin-2fa@test.com',
            'password' => 'password',
        ]);
        $user->assignRole('admin');

        return $user;
    }
}
