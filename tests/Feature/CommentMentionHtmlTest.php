<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

it('resolves @mentions from a rich-text (HTML) comment body', function () {
    $owner = User::factory()->create(['name' => 'Owner Person']);
    $member = User::factory()->create(['name' => 'Jane Doe']);

    $board = Board::create(['user_id' => $owner->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $member->id, 'permission' => 'write']);

    // Body is rich-text HTML with the mention wrapped in a mention span (as TipTap emits),
    // plus an inline image — the mention text "@JaneDoe" still lives inside the HTML.
    $body = '<p>Hey <span class="mention" data-id="'.$member->id.'">@JaneDoe</span>, see this '
          .'<img src="/storage/cards/1/x.png"></p>';

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/comments", ['body' => $body])
        ->assertCreated()
        ->assertJsonFragment(['body' => $body]);

    // The mentioned member got a database notification.
    $member->refresh();
    expect($member->notifications->contains(function ($n) use ($card) {
        return ($n->data['card_id'] ?? null) === $card->id
            && str_contains($n->data['message'] ?? '', 'mentioned you');
    }))->toBeTrue();
});
