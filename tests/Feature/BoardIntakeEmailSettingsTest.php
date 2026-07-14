<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\User;

/** An owned board with the default section set. */
function settingsBoard(User $owner): Board
{
    return Board::create(['user_id' => $owner->id, 'name' => 'Board', 'description' => '']);
}

it('enabling intake mints a token and reports it connected; disabling clears it', function () {
    $owner = User::factory()->create();
    $board = settingsBoard($owner);

    $res = $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}", ['intake_enabled' => true])
        ->assertOk();

    $token = $res->json('intake_token');
    expect($token)->toBeString()->not->toBeEmpty();
    expect($res->json('intake_connected'))->toBeTrue();

    // Re-enabling keeps the same token (no churn).
    $again = $this->actingAs($owner)->putJson("/api/boards/{$board->id}", ['intake_enabled' => true]);
    expect($again->json('intake_token'))->toBe($token);

    // Disabling clears it.
    $off = $this->actingAs($owner)->putJson("/api/boards/{$board->id}", ['intake_enabled' => false]);
    expect($off->json('intake_token'))->toBeNull();
    expect($off->json('intake_connected'))->toBeFalse();
});

it('defaults email_spam_safe on and require_optin off, and toggles both', function () {
    $owner = User::factory()->create();
    $board = settingsBoard($owner);

    expect($board->fresh()->email_spam_safe)->toBeTrue();
    expect($board->fresh()->require_optin_before_email)->toBeFalse();

    $this->actingAs($owner)->putJson("/api/boards/{$board->id}", [
        'email_spam_safe' => false,
        'require_optin_before_email' => true,
    ])->assertOk();

    $board->refresh();
    expect($board->email_spam_safe)->toBeFalse();
    expect($board->require_optin_before_email)->toBeTrue();
});

it('persists a valid intake field map and clears it with null', function () {
    $owner = User::factory()->create();
    $board = settingsBoard($owner);

    $map = [
        ['source' => 'budget', 'target' => 'value'],
        ['source' => 'service', 'target' => 'tags'],
    ];
    $this->actingAs($owner)->putJson("/api/boards/{$board->id}", ['intake_field_map' => $map])->assertOk();
    expect($board->fresh()->intake_field_map)->toBe($map);

    $this->actingAs($owner)->putJson("/api/boards/{$board->id}", ['intake_field_map' => null])->assertOk();
    expect($board->fresh()->intake_field_map)->toBeNull();
});

it('rejects an intake field map with an unknown target', function () {
    $owner = User::factory()->create();
    $board = settingsBoard($owner);

    $this->actingAs($owner)->putJson("/api/boards/{$board->id}", [
        'intake_field_map' => [['source' => 'budget', 'target' => 'not_a_target']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['intake_field_map.0.target']);
});

it('hides the intake token from non-managers but still reports connected', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $board = settingsBoard($owner);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $viewer->id, 'permission' => 'read']);

    $this->actingAs($owner)->putJson("/api/boards/{$board->id}", ['intake_enabled' => true])->assertOk();

    $payload = $this->actingAs($viewer)->getJson("/api/boards/{$board->id}")->assertOk();
    expect($payload->json('intake_connected'))->toBeTrue();
    expect($payload->json('intake_token'))->toBeNull();
});
