<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Covers both admin-triggered password flows on staff accounts:
 *   - resetPassword: sends a Fortify password-reset email
 *   - setPassword:   admin directly writes a new password hash
 */
final class UserPasswordTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────

    private function adminWith(string ...$permissions): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach ($permissions as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $user->roles()->syncWithoutDetaching($role);

        return $user;
    }

    private function staffUser(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    private function clientUser(): User
    {
        return User::factory()->create(['role' => 'client']);
    }

    // ── resetPassword (send email) ────────────────────────────────────────

    public function test_reset_email_redirects_guests(): void
    {
        $target = $this->staffUser();

        $this->post(route('admin.users.reset-password', $target))
            ->assertRedirect(route('admin.login'));
    }

    public function test_reset_email_requires_users_edit_permission(): void
    {
        $actor = $this->adminWith(); // no permissions
        $target = $this->staffUser();

        $this->actingAs($actor)
            ->post(route('admin.users.reset-password', $target))
            ->assertForbidden();
    }

    public function test_reset_email_rejects_client_accounts(): void
    {
        $actor  = $this->adminWith('users.edit');
        $client = $this->clientUser();

        $this->actingAs($actor)
            ->post(route('admin.users.reset-password', $client))
            ->assertNotFound();
    }

    public function test_reset_email_sends_notification_and_flashes_success(): void
    {
        Notification::fake();

        $actor  = $this->adminWith('users.edit');
        $target = $this->staffUser();

        $this->actingAs($actor)
            ->post(route('admin.users.reset-password', $target))
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo($target, ResetPassword::class);
    }

    public function test_reset_email_logs_activity(): void
    {
        Notification::fake();

        $actor  = $this->adminWith('users.edit');
        $target = $this->staffUser();

        $this->actingAs($actor)
            ->post(route('admin.users.reset-password', $target));

        $this->assertDatabaseHas('activity_log', [
            'action' => 'password_reset_email',
            'user_id' => $actor->id,
        ]);
    }

    // ── setPassword (direct) ──────────────────────────────────────────────

    public function test_set_password_redirects_guests(): void
    {
        $target = $this->staffUser();

        $this->post(route('admin.users.set-password', $target), [
            'new_password'              => 'NewPass1',
            'new_password_confirmation' => 'NewPass1',
        ])->assertRedirect(route('admin.login'));
    }

    public function test_set_password_requires_users_edit_permission(): void
    {
        $actor  = $this->adminWith(); // no permissions
        $target = $this->staffUser();

        $this->actingAs($actor)
            ->post(route('admin.users.set-password', $target), [
                'new_password'              => 'NewPass1',
                'new_password_confirmation' => 'NewPass1',
            ])->assertForbidden();
    }

    public function test_set_password_rejects_client_accounts(): void
    {
        $actor  = $this->adminWith('users.edit');
        $client = $this->clientUser();

        $this->actingAs($actor)
            ->post(route('admin.users.set-password', $client), [
                'new_password'              => 'NewPass1',
                'new_password_confirmation' => 'NewPass1',
            ])->assertNotFound();
    }

    public function test_set_password_requires_new_password_field(): void
    {
        $actor  = $this->adminWith('users.edit');
        $target = $this->staffUser();

        $this->actingAs($actor)
            ->post(route('admin.users.set-password', $target), [])
            ->assertSessionHasErrors('new_password');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('weakPasswordProvider')]
    public function test_set_password_rejects_weak_passwords(string $password, string $confirm): void
    {
        $actor  = $this->adminWith('users.edit');
        $target = $this->staffUser();

        $this->actingAs($actor)
            ->post(route('admin.users.set-password', $target), [
                'new_password'              => $password,
                'new_password_confirmation' => $confirm,
            ])->assertSessionHasErrors('new_password');
    }

    public static function weakPasswordProvider(): array
    {
        return [
            'too short'     => ['Ab1',        'Ab1'],
            'no uppercase'  => ['newpass1',   'newpass1'],
            'no lowercase'  => ['NEWPASS1',   'NEWPASS1'],
            'no digit'      => ['NewPassword', 'NewPassword'],
            'not confirmed' => ['NewPass1',   'DifferentPass1'],
        ];
    }

    public function test_set_password_updates_hash_in_database(): void
    {
        $actor     = $this->adminWith('users.edit');
        $target    = $this->staffUser();
        $oldHash   = $target->password_hash;

        $this->actingAs($actor)
            ->post(route('admin.users.set-password', $target), [
                'new_password'              => 'NewPass1',
                'new_password_confirmation' => 'NewPass1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $target->refresh();
        $this->assertNotSame($oldHash, $target->password_hash);
        $this->assertTrue(Hash::check('NewPass1', $target->password_hash));
    }

    public function test_set_password_logs_activity(): void
    {
        $actor  = $this->adminWith('users.edit');
        $target = $this->staffUser();

        $this->actingAs($actor)
            ->post(route('admin.users.set-password', $target), [
                'new_password'              => 'NewPass1',
                'new_password_confirmation' => 'NewPass1',
            ]);

        $this->assertDatabaseHas('activity_log', [
            'action'  => 'password_set',
            'user_id' => $actor->id,
        ]);
    }

    // ── rate limiting ─────────────────────────────────────────────────────

    /** Build the limiter key the way AppServiceProvider does. */
    private function resetEmailKey(User $actor, User $target): string
    {
        return $actor->id.'|admin/users/'.$target->id.'/reset-password';
    }

    private function setPasswordKey(User $actor, User $target): string
    {
        return $actor->id.'|admin/users/'.$target->id.'/set-password';
    }

    public function test_reset_email_throttled_after_three_requests(): void
    {
        Notification::fake();

        $actor  = $this->adminWith('users.edit');
        $target = $this->staffUser();
        $route  = route('admin.users.reset-password', $target);

        RateLimiter::clear($this->resetEmailKey($actor, $target));

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($actor)->post($route)->assertRedirect();
        }

        $this->actingAs($actor)
            ->post($route)
            ->assertRedirect()
            ->assertSessionHasErrors('error');
    }

    public function test_set_password_throttled_after_five_requests(): void
    {
        $actor  = $this->adminWith('users.edit');
        $target = $this->staffUser();
        $route  = route('admin.users.set-password', $target);
        $data   = ['new_password' => 'NewPass1', 'new_password_confirmation' => 'NewPass1'];

        RateLimiter::clear($this->setPasswordKey($actor, $target));

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($actor)->post($route, $data)->assertRedirect();
        }

        $this->actingAs($actor)
            ->post($route, $data)
            ->assertRedirect()
            ->assertSessionHasErrors('error');
    }

    public function test_reset_email_limit_is_per_admin_not_shared(): void
    {
        Notification::fake();

        $actorA = $this->adminWith('users.edit');
        $actorB = $this->adminWith('users.edit');
        $target = $this->staffUser();
        $route  = route('admin.users.reset-password', $target);

        RateLimiter::clear($this->resetEmailKey($actorA, $target));
        RateLimiter::clear($this->resetEmailKey($actorB, $target));

        // Exhaust actorA's quota entirely.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($actorA)->post($route)->assertRedirect();
        }
        $this->actingAs($actorA)->post($route)
            ->assertRedirect()->assertSessionHasErrors('error');

        // actorB has a separate bucket — first request must still succeed.
        $this->actingAs($actorB)->post($route)
            ->assertRedirect()->assertSessionMissing('error');
    }

    public function test_reset_email_limit_is_per_target_not_shared(): void
    {
        Notification::fake();

        $actor   = $this->adminWith('users.edit');
        $targetX = $this->staffUser();
        $targetY = $this->staffUser();

        RateLimiter::clear($this->resetEmailKey($actor, $targetX));
        RateLimiter::clear($this->resetEmailKey($actor, $targetY));

        // Exhaust the quota for targetX.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($actor)
                ->post(route('admin.users.reset-password', $targetX))
                ->assertRedirect();
        }
        $this->actingAs($actor)
            ->post(route('admin.users.reset-password', $targetX))
            ->assertRedirect()->assertSessionHasErrors('error');

        // targetY has a separate bucket — first request must still succeed.
        $this->actingAs($actor)
            ->post(route('admin.users.reset-password', $targetY))
            ->assertRedirect()->assertSessionMissing('error');
    }

    public function test_set_password_limit_is_per_admin_not_shared(): void
    {
        $actorA = $this->adminWith('users.edit');
        $actorB = $this->adminWith('users.edit');
        $target = $this->staffUser();
        $route  = route('admin.users.set-password', $target);
        $data   = ['new_password' => 'NewPass1', 'new_password_confirmation' => 'NewPass1'];

        RateLimiter::clear($this->setPasswordKey($actorA, $target));
        RateLimiter::clear($this->setPasswordKey($actorB, $target));

        // Exhaust actorA's quota.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($actorA)->post($route, $data)->assertRedirect();
        }
        $this->actingAs($actorA)->post($route, $data)
            ->assertRedirect()->assertSessionHasErrors('error');

        // actorB's bucket is untouched.
        $this->actingAs($actorB)->post($route, $data)
            ->assertRedirect()->assertSessionMissing('error');
    }

    public function test_set_password_limit_is_per_target_not_shared(): void
    {
        $actor   = $this->adminWith('users.edit');
        $targetX = $this->staffUser();
        $targetY = $this->staffUser();
        $data    = ['new_password' => 'NewPass1', 'new_password_confirmation' => 'NewPass1'];

        RateLimiter::clear($this->setPasswordKey($actor, $targetX));
        RateLimiter::clear($this->setPasswordKey($actor, $targetY));

        // Exhaust quota for targetX.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($actor)
                ->post(route('admin.users.set-password', $targetX), $data)
                ->assertRedirect();
        }
        $this->actingAs($actor)
            ->post(route('admin.users.set-password', $targetX), $data)
            ->assertRedirect()->assertSessionHasErrors('error');

        // targetY's bucket is untouched.
        $this->actingAs($actor)
            ->post(route('admin.users.set-password', $targetY), $data)
            ->assertRedirect()->assertSessionMissing('error');
    }
}
