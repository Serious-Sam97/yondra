<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

function jamFixture(): array
{
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'B', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'Doing', 'order' => 0]);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'C', 'description' => '']);

    return [$user, $board, $card];
}

it('jams a card with a reason and keeps the original blocked_at', function () {
    [$user, $board, $card] = jamFixture();

    $res = $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['blocked_reason' => 'Waiting on legal'])->assertOk();
    expect($res->json('blocked_reason'))->toBe('Waiting on legal');
    expect($res->json('blocked_at'))->not->toBeNull();

    $first = $card->fresh()->blocked_at;
    $this->travel(1)->hours();
    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['blocked_reason' => 'Still waiting'])->assertOk();
    expect($card->fresh()->blocked_at->equalTo($first))->toBeTrue();
});

it('unjams a card with an empty reason', function () {
    [$user, $board, $card] = jamFixture();
    $card->update(['blocked_reason' => 'x', 'blocked_at' => now()]);

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['blocked_reason' => ''])->assertOk();

    expect($card->fresh()->blocked_reason)->toBeNull();
    expect($card->fresh()->blocked_at)->toBeNull();
});

it('leaves the jam untouched on unrelated updates', function () {
    [$user, $board, $card] = jamFixture();
    $card->update(['blocked_reason' => 'x', 'blocked_at' => now()]);

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['name' => 'Renamed'])->assertOk();

    expect($card->fresh()->blocked_reason)->toBe('x');
});
