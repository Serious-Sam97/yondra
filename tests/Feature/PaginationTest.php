<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

/**
 * Pagination contracts for the two previously unbounded lists. The frontend is
 * typed against these exact envelopes:
 *  - comments: bare simplePaginate payload (`data` + `next_page_url`), newest first
 *  - archived cards: paginated CardResource collection — withoutWrapping() does NOT
 *    strip the `data`/`links`/`meta` envelope for paginated resource collections,
 *    so clients read `links.next`.
 */
function paginationBoardWithSection(User $user): array
{
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);

    return [$board, $section];
}

it('paginates card comments newest-first, 30 per page', function () {
    $user = User::factory()->create();
    [$board, $section] = paginationBoardWithSection($user);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);

    $ids = [];
    foreach (range(1, 35) as $i) {
        $ids[] = CardComment::create(['card_id' => $card->id, 'user_id' => $user->id, 'body' => "comment {$i}"])->id;
    }

    $this->actingAs($user);

    // Page 1: the 30 newest comments (id tie-breaks same-second created_at),
    // with a non-null pointer to the next page.
    $page1 = $this->getJson("/api/boards/{$board->id}/cards/{$card->id}/comments")
        ->assertOk()
        ->assertJsonCount(30, 'data')
        ->json();
    expect($page1['next_page_url'])->not->toBeNull();
    expect($page1['per_page'])->toBe(30);
    expect(array_column($page1['data'], 'id'))->toBe(array_reverse(array_slice($ids, 5)));
    // The author stays eager-loaded on paginated rows.
    expect($page1['data'][0]['user'])->toMatchArray(['id' => $user->id, 'name' => $user->name]);

    // Page 2: the remaining 5 oldest, and the pagination stops (next is null).
    $page2 = $this->getJson("/api/boards/{$board->id}/cards/{$card->id}/comments?page=2")
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->json();
    expect($page2['next_page_url'])->toBeNull();
    expect(array_column($page2['data'], 'id'))->toBe(array_reverse(array_slice($ids, 0, 5)));
});

it('paginates archived cards, 25 per page, keeping the resource envelope', function () {
    $user = User::factory()->create();
    [$board, $section] = paginationBoardWithSection($user);

    $ids = [];
    foreach (range(1, 30) as $i) {
        $ids[] = Card::create([
            'board_id' => $board->id,
            'section_id' => $section->id,
            'name' => "Archived {$i}",
            'description' => '',
            'archived_at' => now(),
        ])->id;
    }

    $this->actingAs($user);

    // Page 1: 25 newest-archived cards (id tie-breaks identical archived_at) inside
    // the data/links/meta envelope that paginated resource collections always keep.
    $page1 = $this->getJson("/api/boards/{$board->id}/cards/archived")
        ->assertOk()
        ->assertJsonStructure(['data', 'links' => ['next'], 'meta' => ['current_page', 'per_page']])
        ->assertJsonCount(25, 'data')
        ->json();
    expect($page1['links']['next'])->not->toBeNull();
    expect($page1['meta']['per_page'])->toBe(25);
    expect(array_column($page1['data'], 'id'))->toBe(array_reverse(array_slice($ids, 5)));

    // Page 2: the remaining 5, last page (links.next is null).
    $page2 = $this->getJson("/api/boards/{$board->id}/cards/archived?page=2")
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->json();
    expect($page2['links']['next'])->toBeNull();
    expect(array_column($page2['data'], 'id'))->toBe(array_reverse(array_slice($ids, 0, 5)));
});
