<?php

use App\Infrastructure\Models\User;

it('rejects unauthenticated requests to vortex', function () {
    $this->getJson('/api/vortex/me')->assertUnauthorized();
});

it('rejects authenticated non-admin users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/vortex/me')
        ->assertForbidden();
});

it('allows admin users', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->getJson('/api/vortex/me')
        ->assertOk()
        ->assertJsonPath('email', $admin->email);
});

it('rejects an admin whose email is not on a non-empty allowlist', function () {
    config()->set('vortex.admin_emails', 'someone-else@example.com');
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->getJson('/api/vortex/me')
        ->assertForbidden();
});

it('allows an admin whose email is on the allowlist', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    config()->set('vortex.admin_emails', "other@example.com, {$admin->email}");

    $this->actingAs($admin)
        ->getJson('/api/vortex/me')
        ->assertOk();
});

it('cannot mass-assign is_admin through registration', function () {
    $this->postJson('/api/register', [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'is_admin' => true,
    ]);

    $user = User::where('email', 'sneaky@example.com')->first();

    if ($user) {
        expect($user->is_admin)->toBeFalse();
    } else {
        // Registration rejected the payload entirely — also fine.
        expect(User::where('is_admin', true)->count())->toBe(0);
    }
});
