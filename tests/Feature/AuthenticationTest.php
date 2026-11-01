<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Masuk ke akun Anda');
    }

    public function test_every_role_can_log_in_with_username(): void
    {
        foreach (Role::cases() as $role) {
            $user = User::factory()->role($role)->create(['username' => strtolower($role->value), 'password' => 'secret123']);

            $this->post('/login', ['username' => strtoupper($user->username), 'password' => 'secret123'])
                ->assertRedirect('/dashboard');
            $this->assertAuthenticatedAs($user);
            $this->get('/dashboard')->assertOk();

            $this->post('/logout')->assertRedirect('/login');
            $this->assertGuest();
        }
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'cs', 'password' => 'secret123']);

        $this->from('/login')->post('/login', ['username' => 'cs', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in_and_is_signed_out_when_deactivated(): void
    {
        User::factory()->inactive()->create(['username' => 'old', 'password' => 'secret123']);
        $this->post('/login', ['username' => 'old', 'password' => 'secret123'])->assertSessionHasErrors('username');
        $this->assertGuest();

        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $user->update(['active' => false]);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        User::factory()->create(['username' => 'cs', 'password' => 'secret123']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'cs', 'password' => 'wrong']);
        }

        $this->post('/login', ['username' => 'cs', 'password' => 'secret123'])
            ->assertSessionHasErrors('username');
        $this->assertStringContainsString('Terlalu banyak percobaan login', session('errors')->first('username'));
        $this->assertGuest();
    }

    public function test_guessing_one_account_from_many_ips_is_throttled(): void
    {
        User::factory()->create(['username' => 'spv', 'password' => 'secret123']);

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->post('/login', ['username' => 'spv', 'password' => 'wrong']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->post('/login', ['username' => 'spv', 'password' => 'secret123'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_trying_passwords_across_many_accounts_from_one_ip_is_throttled(): void
    {
        User::factory()->create(['username' => 'target', 'password' => 'secret123']);

        for ($i = 0; $i < 50; $i++) {
            $this->post('/login', ['username' => "user{$i}", 'password' => 'qcnet123']);
        }

        $this->post('/login', ['username' => 'target', 'password' => 'secret123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_user_can_change_own_password(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'wrong',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertSessionHasErrors('current_password');

        $this->put('/profile/password', [
            'current_password' => 'secret123',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(password_verify('newpass123', $user->fresh()->password));
    }
}
