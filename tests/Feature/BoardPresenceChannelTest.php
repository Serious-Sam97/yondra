<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\User;
use Illuminate\Support\Facades\Broadcast;

// The presence callback decides who may join board-presence.{id} and what they
// expose; test the callback directly (no broadcaster round trip needed).
function presenceAuth(User $user, int $boardId): mixed
{
    $channels = (fn () => $this->channels)->call(Broadcast::driver());

    return $channels['board-presence.{boardId}']($user, $boardId);
}

it('lets board members join with only their id and name', function () {
    $owner = User::factory()->create(['name' => 'Ana']);
    $board = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '']);

    expect(presenceAuth($owner, $board->id))->toBe(['id' => $owner->id, 'name' => 'Ana']);
});

it('keeps strangers out', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '']);

    expect(presenceAuth($stranger, $board->id))->toBeFalse();
});
