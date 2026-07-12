<?php

use App\Infrastructure\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

it('throttles login attempts after five per minute', function () {
    User::factory()->create(['email' => 'victim@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', ['email' => 'victim@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    $this->postJson('/api/login', ['email' => 'victim@example.com', 'password' => 'wrong-password'])
        ->assertStatus(429);
});

it('throttles registration after five per minute', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/register', [
            'name' => "Bot {$i}",
            'email' => "bot{$i}@example.com",
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertCreated();
    }

    $this->postJson('/api/register', [
        'name' => 'Bot 6',
        'email' => 'bot6@example.com',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
    ])->assertStatus(429);
});

it('issues tokens with an expiration so a stolen token ages out', function () {
    expect((int) config('sanctum.expiration'))->toBeGreaterThan(0);
});

it('revokes every other token on password update, keeping the current session', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password-1')]);
    $current = $user->createToken('current')->plainTextToken;
    $user->createToken('stolen');

    $this->withToken($current)
        ->putJson('/api/user/password', [
            'current_password' => 'old-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

    expect($user->fresh()->tokens()->pluck('name')->all())->toBe(['current']);
});

it('revokes every token on password reset', function () {
    $user = User::factory()->create();
    $user->createToken('laptop');
    $user->createToken('stolen');
    $token = Password::createToken($user);

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-pass-1',
        'password_confirmation' => 'brand-new-pass-1',
    ])->assertOk();

    expect($user->fresh()->tokens()->count())->toBe(0);
});
