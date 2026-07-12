<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

/**
 * Serialized-shape contract for the API resource layer (BoardResource,
 * BoardSummaryResource, CardResource). The frontend is typed against these
 * exact shapes — field presence/absence here is load-bearing.
 */
function makeConnectedBoard(User $owner): Board
{
    $board = Board::create([
        'user_id' => $owner->id,
        'name' => 'Ops',
        'description' => '',
        'ticket_prefix' => 'OPS',
        'github_token' => 'gh-secret-token',
        'github_webhook_secret' => 'hook-secret',
        'whatsapp_token' => 'wa-secret-token',
        'whatsapp_app_secret' => 'wa-app-secret',
        'whatsapp_verify_token' => 'verify-me',
    ]);
    Section::create(['board_id' => $board->id, 'name' => 'To Do', 'order' => 0]);

    return $board;
}

it('board show exposes capabilities and webhook secrets to the owner but never raw tokens', function () {
    $owner = User::factory()->create();
    $board = makeConnectedBoard($owner);

    $json = $this->actingAs($owner)
        ->getJson("/api/boards/{$board->id}")
        ->assertOk()
        ->json();

    expect($json['can_write'])->toBeTrue();
    expect($json['can_manage'])->toBeTrue();
    expect($json['github_connected'])->toBeTrue();
    expect($json['whatsapp_connected'])->toBeTrue();

    // Managers see the webhook-setup fields...
    expect($json['github_webhook_secret'])->toBe('hook-secret');
    expect($json['whatsapp_verify_token'])->toBe('verify-me');

    // ...but the raw tokens never leave the server.
    expect($json)->not->toHaveKey('github_token');
    expect($json)->not->toHaveKey('whatsapp_token');
    expect($json)->not->toHaveKey('whatsapp_app_secret');
});

it('board show nulls webhook secrets for a shared non-manager while keeping capabilities', function () {
    $owner = User::factory()->create();
    $writer = User::factory()->create();
    $board = makeConnectedBoard($owner);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $writer->id, 'permission' => 'write']);

    $json = $this->actingAs($writer)
        ->getJson("/api/boards/{$board->id}")
        ->assertOk()
        ->json();

    expect($json['can_write'])->toBeTrue();
    expect($json['can_manage'])->toBeFalse();
    expect($json['github_connected'])->toBeTrue();
    expect($json['whatsapp_connected'])->toBeTrue();

    // The webhook-setup fields stay present but are redacted to null.
    expect($json)->toHaveKey('github_webhook_secret');
    expect($json['github_webhook_secret'])->toBeNull();
    expect($json)->toHaveKey('whatsapp_verify_token');
    expect($json['whatsapp_verify_token'])->toBeNull();

    expect($json)->not->toHaveKey('github_token');
    expect($json)->not->toHaveKey('whatsapp_token');
    expect($json)->not->toHaveKey('whatsapp_app_secret');

    // Collaborators carry their flattened share permission.
    expect(collect($json['shared_with'])->firstWhere('id', $writer->id)['permission'])->toBe('write');
});

it('card create and update responses carry the composed ticket_key', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Zed', 'description' => '', 'ticket_prefix' => 'ZED']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do', 'order' => 0]);

    $this->actingAs($user);

    $created = $this->postJson("/api/boards/{$board->id}/cards", ['section_id' => $section->id, 'name' => 'Task'])
        ->assertCreated()
        ->json();
    expect($created['ticket_key'])->toBe('ZED-1');

    $updated = $this->putJson("/api/boards/{$board->id}/cards/{$created['id']}", ['name' => 'Task v2'])
        ->assertOk()
        ->json();
    expect($updated['name'])->toBe('Task v2');
    expect($updated['ticket_key'])->toBe('ZED-1');
});

it('search and dashboard ticket keys keep the PREFIX-N / #N format', function () {
    $user = User::factory()->create();

    $prefixed = Board::create(['user_id' => $user->id, 'name' => 'Growth', 'description' => '', 'ticket_prefix' => 'GRO']);
    $todo = Section::create(['board_id' => $prefixed->id, 'name' => 'To Do', 'order' => 0]);
    Card::create([
        'board_id' => $prefixed->id,
        'section_id' => $todo->id,
        'name' => 'Prefixed task',
        'description' => '',
        'ticket_number' => 7,
        'assigned_user_id' => $user->id,
        'due_date' => now()->subDay()->toDateString(),
    ]);

    $bare = Board::create(['user_id' => $user->id, 'name' => 'Plain', 'description' => '']);
    $lane = Section::create(['board_id' => $bare->id, 'name' => 'To Do', 'order' => 0]);
    Card::create(['board_id' => $bare->id, 'section_id' => $lane->id, 'name' => 'Bare task', 'description' => '', 'ticket_number' => 3]);

    $this->actingAs($user);

    expect($this->getJson('/api/search?q=Prefixed')->assertOk()->json('cards.0.ticket_key'))->toBe('GRO-7');
    expect($this->getJson('/api/search?q=Bare')->assertOk()->json('cards.0.ticket_key'))->toBe('#3');

    // The overdue assigned card surfaces on the dashboard queue with the same key.
    $overdue = $this->getJson('/api/dashboard')->assertOk()->json('queue.overdue');
    expect(collect($overdue)->firstWhere('name', 'Prefixed task')['ticket_key'])->toBe('GRO-7');
});
