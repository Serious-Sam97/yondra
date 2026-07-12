<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\CommentReaction;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

function commentCard(User $owner): array
{
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    return [$board, $card];
}

it('threads a reply under its parent and summarizes it on the index', function () {
    $owner = User::factory()->create();
    [$board, $card] = commentCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/comments";

    $root = $this->actingAs($owner)->postJson($base, ['body' => '<p>root</p>'])->assertCreated()->json();
    $reply = $this->actingAs($owner)
        ->postJson($base, ['body' => '<p>reply</p>', 'parent_id' => $root['id']])
        ->assertCreated()
        ->json();

    expect($reply['parent_id'])->toBe($root['id']);

    // Index lists only top-level comments, carrying the thread summary.
    $index = $this->actingAs($owner)->getJson($base)->assertOk()->json('data');
    expect($index)->toHaveCount(1)
        ->and($index[0]['id'])->toBe($root['id'])
        ->and($index[0]['replies_count'])->toBe(1)
        ->and($index[0]['last_reply_at'])->not->toBeNull();

    // The thread endpoint returns the reply in conversation order.
    $replies = $this->actingAs($owner)
        ->getJson("{$base}/{$root['id']}/replies")
        ->assertOk()
        ->json('data');
    expect($replies)->toHaveCount(1)
        ->and($replies[0]['id'])->toBe($reply['id']);
});

it('re-parents a reply-to-a-reply onto the thread root (single-level threads)', function () {
    $owner = User::factory()->create();
    [$board, $card] = commentCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/comments";

    $root = $this->actingAs($owner)->postJson($base, ['body' => '<p>root</p>'])->json();
    $reply = $this->actingAs($owner)
        ->postJson($base, ['body' => '<p>reply</p>', 'parent_id' => $root['id']])->json();
    $deep = $this->actingAs($owner)
        ->postJson($base, ['body' => '<p>deeper</p>', 'parent_id' => $reply['id']])
        ->assertCreated()
        ->json();

    expect($deep['parent_id'])->toBe($root['id']);
});

it('rejects a parent from another card', function () {
    $owner = User::factory()->create();
    [$board, $card] = commentCard($owner);
    $section = Section::create(['board_id' => $board->id, 'name' => 'Other']);
    $otherCard = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Other', 'description' => '']);
    $foreign = CardComment::create(['card_id' => $otherCard->id, 'user_id' => $owner->id, 'body' => '<p>x</p>']);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/comments", [
            'body' => '<p>reply</p>',
            'parent_id' => $foreign->id,
        ])
        ->assertNotFound();
});

it('deletes a thread with its root', function () {
    $owner = User::factory()->create();
    [$board, $card] = commentCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/comments";

    $root = $this->actingAs($owner)->postJson($base, ['body' => '<p>root</p>'])->json();
    $this->actingAs($owner)->postJson($base, ['body' => '<p>reply</p>', 'parent_id' => $root['id']]);

    $this->actingAs($owner)->deleteJson("{$base}/{$root['id']}")->assertNoContent();
    expect(CardComment::where('card_id', $card->id)->count())->toBe(0);
});

it('notifies thread participants on a reply (not the whole card)', function () {
    $owner = User::factory()->create();
    $mate = User::factory()->create();
    $bystander = User::factory()->create();
    [$board, $card] = commentCard($owner);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $mate->id, 'permission' => 'write']);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $bystander->id, 'permission' => 'write']);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/comments";

    $root = $this->actingAs($owner)->postJson($base, ['body' => '<p>root</p>'])->json();
    $this->actingAs($mate)->postJson($base, ['body' => '<p>in thread</p>', 'parent_id' => $root['id']])->assertCreated();

    $owner->refresh();
    $bystander->refresh();
    expect($owner->notifications->contains(fn ($n) => ($n->data['type'] ?? '') === 'comment.replied'))->toBeTrue()
        ->and($bystander->notifications->count())->toBe(0);
});

it('toggles reactions and aggregates them per emoji', function () {
    $owner = User::factory()->create();
    $mate = User::factory()->create();
    [$board, $card] = commentCard($owner);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $mate->id, 'permission' => 'read']);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/comments";

    $root = $this->actingAs($owner)->postJson($base, ['body' => '<p>root</p>'])->json();

    $this->actingAs($owner)->postJson("{$base}/{$root['id']}/reactions", ['emoji' => '👍'])->assertOk();
    $res = $this->actingAs($mate)->postJson("{$base}/{$root['id']}/reactions", ['emoji' => '👍'])->assertOk();

    $agg = collect($res->json('reactions'))->firstWhere('emoji', '👍');
    expect($agg['count'])->toBe(2)
        ->and($agg['user_ids'])->toContain($owner->id, $mate->id);

    // Toggling again removes only my row.
    $res = $this->actingAs($mate)->postJson("{$base}/{$root['id']}/reactions", ['emoji' => '👍'])->assertOk();
    $agg = collect($res->json('reactions'))->firstWhere('emoji', '👍');
    expect($agg['count'])->toBe(1)
        ->and($agg['user_ids'])->not->toContain($mate->id);
    expect(CommentReaction::count())->toBe(1);
});

it('answers 503 for gif search without a key and reports availability', function () {
    $owner = User::factory()->create();
    config(['services.tenor.key' => null]);

    $this->actingAs($owner)->getJson('/api/gifs/availability')
        ->assertOk()
        ->assertJson(['enabled' => false]);
    $this->actingAs($owner)->getJson('/api/gifs/search?q=cat')->assertStatus(503);

    config(['services.tenor.key' => 'test-key']);
    $this->actingAs($owner)->getJson('/api/gifs/availability')
        ->assertOk()
        ->assertJson(['enabled' => true]);
});
