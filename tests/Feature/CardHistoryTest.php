<?php

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardActivity;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Tag;
use App\Infrastructure\Models\User;
use App\Services\CardHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** @return array{0: User, 1: Board, 2: Section, 3: Section} */
function historyBoard(): array
{
    $user = User::factory()->create(['name' => 'Sam']);
    $board = Board::create(['user_id' => $user->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm']);
    $lead = Section::create(['board_id' => $board->id, 'name' => 'Lead']);
    $won = Section::create(['board_id' => $board->id, 'name' => 'Won']);

    return [$user, $board, $lead, $won];
}

function historyOf(Card $card): array
{
    return CardActivity::where('card_id', $card->id)->orderBy('id')->get()->toArray();
}

it('records one card.created entry for a create, even with tags and contact', function () {
    [$user, $board, $lead] = historyBoard();
    $tag = Tag::create(['board_id' => $board->id, 'name' => 'VIP', 'color' => '#f00']);

    $id = $this->actingAs($user)->postJson("/api/boards/{$board->id}/cards", [
        'section_id' => $lead->id,
        'name' => 'Acme deal',
        'tag_ids' => [$tag->id],
        'contact' => ['name' => 'Ann', 'email' => 'ann@acme.test', 'phone' => ''],
    ])->assertCreated()->json('id');

    $entries = historyOf(Card::find($id));
    expect($entries)->toHaveCount(1);
    expect($entries[0]['type'])->toBe('card.created')
        ->and($entries[0]['user_id'])->toBe($user->id)
        ->and($entries[0]['source'])->toBe('user')
        ->and($entries[0]['meta'])->toMatchArray(['name' => 'Acme deal', 'section' => 'Lead']);
});

it('merges one save into a single entry with labelled from/to values', function () {
    [$user, $board, $lead, $won] = historyBoard();
    $vip = Tag::create(['board_id' => $board->id, 'name' => 'VIP', 'color' => '#f00']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Acme', 'description' => '']);
    CardActivity::query()->delete();

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", [
        'name' => 'Acme renewal',
        'section_id' => $won->id,
        'priority' => 'high',
        'value' => 1500,
        'assigned_user_id' => $user->id,
        'tag_ids' => [$vip->id],
        'contact' => ['name' => 'Ann', 'email' => 'ann@acme.test', 'phone' => ''],
    ])->assertOk();

    $entries = historyOf($card);
    expect($entries)->toHaveCount(1);
    $c = $entries[0]['changes'];
    expect($entries[0]['type'])->toBe('card.updated')
        ->and($c['name'])->toBe(['from' => 'Acme', 'to' => 'Acme renewal'])
        ->and($c['section_id'])->toBe(['from' => 'Lead', 'to' => 'Won'])
        ->and($c['priority'])->toBe(['from' => null, 'to' => 'high'])
        ->and($c['value'])->toEqual(['from' => null, 'to' => 1500])
        ->and($c['assigned_user_id'])->toBe(['from' => null, 'to' => 'Sam'])
        ->and($c['tags'])->toBe(['added' => ['VIP'], 'removed' => []])
        ->and($c['contact.email'])->toBe(['from' => null, 'to' => 'ann@acme.test'])
        ->and($c)->not->toHaveKey('contact.phone')
        ->and($c)->not->toHaveKey('position');
});

it('records nothing for a save that changes nothing', function () {
    [$user, $board, $lead] = historyBoard();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Acme', 'description' => '']);
    CardActivity::query()->delete();

    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}", [
        'name' => 'Acme',
        'tag_ids' => [],
        'contact' => ['name' => '', 'email' => '', 'phone' => ''],
    ])->assertOk();

    expect(historyOf($card))->toBeEmpty();
});

it('ignores position-only drags but records column moves, with one nudge per reorder', function () {
    [$user, $board, $lead, $won] = historyBoard();
    $a = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'A', 'description' => '', 'position' => 0]);
    $b = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'B', 'description' => '', 'position' => 1]);
    $c = Card::create(['board_id' => $board->id, 'section_id' => $won->id, 'name' => 'C', 'description' => '', 'position' => 0]);
    CardActivity::query()->delete();
    Event::fake([BoardEvent::class]);

    // Same-column shuffle: positions only.
    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/reorder", [
        'section_id' => $lead->id, 'ordered_ids' => [$b->id, $a->id],
    ])->assertOk();
    expect(CardActivity::count())->toBe(0);

    // Pull C and B into Won.
    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/reorder", [
        'section_id' => $won->id, 'ordered_ids' => [$b->id, $c->id],
    ])->assertOk();
    expect(historyOf($b))->toHaveCount(1)
        ->and(historyOf($b)[0]['changes'])->toBe(['section_id' => ['from' => 'Lead', 'to' => 'Won']])
        ->and(historyOf($c))->toBeEmpty();

    $nudges = Event::dispatched(BoardEvent::class, fn (BoardEvent $e) => $e->type === 'card.activity');
    expect($nudges)->toHaveCount(1)
        ->and($nudges->first()[0]->payload)->toBe(['card_ids' => [$b->id]]);
});

it('records archive and restore', function () {
    [$user, $board, $lead] = historyBoard();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Acme', 'description' => '']);
    CardActivity::query()->delete();

    $this->actingAs($user)->deleteJson("/api/boards/{$board->id}/cards/{$card->id}")->assertNoContent();
    $this->actingAs($user)->putJson("/api/boards/{$board->id}/cards/{$card->id}/restore")->assertNoContent();

    expect(array_column(historyOf($card), 'type'))->toBe(['card.archived', 'card.restored']);
});

it('records checklist, comment and subtask activity on the card', function () {
    [$user, $board, $lead] = historyBoard();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Epic', 'description' => '']);
    CardActivity::query()->delete();
    $base = "/api/boards/{$board->id}/cards/{$card->id}";

    $itemId = $this->actingAs($user)->postJson("{$base}/checklist", ['text' => 'Send proposal'])->assertCreated()->json('id');
    $this->actingAs($user)->putJson("{$base}/checklist/{$itemId}", ['is_done' => true])->assertOk();
    $this->actingAs($user)->deleteJson("{$base}/checklist/{$itemId}")->assertSuccessful();
    $this->actingAs($user)->postJson("{$base}/comments", ['body' => '<p>Called <strong>Ann</strong></p>'])->assertCreated();
    $this->actingAs($user)->postJson("{$base}/subtasks", ['name' => 'Draft contract'])->assertCreated();

    $entries = historyOf($card);
    expect(array_column($entries, 'type'))->toBe([
        'checklist.added', 'checklist.completed', 'checklist.removed', 'comment.added', 'subtask.added',
    ]);
    expect($entries[0]['meta'])->toBe(['text' => 'Send proposal'])
        ->and($entries[3]['meta']['excerpt'])->toBe('Called Ann')
        ->and($entries[4]['meta']['name'])->toBe('Draft contract');
});

it('labels CI webhook runs with their source', function () {
    $owner = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '', 'type' => 'kanban', 'qa_enabled' => true]);
    $section = Section::create(['board_id' => $board->id, 'name' => 'Doing']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Login', 'description' => '']);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/qa";
    $caseId = $this->actingAs($owner)->postJson("{$base}/cases", ['title' => 'Logs in'])->json('id');
    $token = $this->actingAs($owner)->postJson("{$base}/cases/{$caseId}/ci-token")->json('ci_token');

    auth()->forgetGuards();
    $this->postJson("/api/webhooks/qa-ci/{$token}", ['status' => 'failed'])->assertCreated();

    $run = CardActivity::where('card_id', $card->id)->where('type', 'qa.run')->sole();
    expect($run->source)->toBe('ci')
        ->and($run->user_id)->toBeNull()
        ->and($run->meta)->toMatchArray(['title' => 'Logs in', 'status' => 'failed']);
});

it('tags entries with the source and via set by CardHistory::as', function () {
    [, $board, $lead] = historyBoard();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Lead', 'description' => '']);
    CardActivity::query()->delete();

    CardHistory::as('webhook', fn () => $card->update(['name' => 'Lead from WhatsApp']), 'whatsapp');

    $entry = CardActivity::where('card_id', $card->id)->sole();
    expect($entry->source)->toBe('webhook')->and($entry->meta)->toBe(['via' => 'whatsapp']);
});

it('leaves no history for a change whose transaction rolls back', function () {
    [, $board, $lead] = historyBoard();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'A', 'description' => '']);
    CardActivity::query()->delete();

    try {
        DB::transaction(function () use ($card) {
            $card->update(['name' => 'B']);
            throw new RuntimeException('quality gate');
        });
    } catch (RuntimeException) {
    }

    expect(Card::find($card->id)->name)->toBe('A')
        ->and(CardActivity::count())->toBe(0);
});

it('keeps the entry and succeeds when the live nudge fails to send', function () {
    [$user, $board, $lead] = historyBoard();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'A', 'description' => '']);
    CardActivity::query()->delete();
    Event::listen(BoardEvent::class, function (BoardEvent $e) {
        if ($e->type === 'card.activity') {
            throw new RuntimeException('reverb unreachable');
        }
    });

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['name' => 'B'])
        ->assertOk();

    expect(CardActivity::where('card_id', $card->id)->sole()->changes['name'])->toBe(['from' => 'A', 'to' => 'B']);
});

it('clips long description snapshots', function () {
    [, $board, $lead] = historyBoard();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'A', 'description' => '']);
    CardActivity::query()->delete();

    $card->update(['description' => str_repeat('x', CardHistory::TEXT_CAP + 500)]);

    $to = CardActivity::sole()->changes['description']['to'];
    expect(mb_strlen($to))->toBe(CardHistory::TEXT_CAP + 1);
});

it('serves history newest first, paged, with a synthesized start for older cards', function () {
    [$user, $board, $lead] = historyBoard();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Old', 'description' => '', 'created_by_user_id' => $user->id]);
    CardActivity::query()->delete(); // simulate a card from before the feature
    foreach (range(1, 31) as $i) {
        $card->update(['name' => "Old {$i}"]);
    }
    $url = "/api/boards/{$board->id}/cards/{$card->id}/history";

    $first = $this->actingAs($user)->getJson($url)->assertOk();
    expect($first->json('data'))->toHaveCount(30)
        ->and($first->json('data.0.changes.name.to'))->toBe('Old 31')
        ->and($first->json('next_page_url'))->not->toBeNull();

    $last = $this->actingAs($user)->getJson("{$url}?page=2")->assertOk();
    expect($last->json('data'))->toHaveCount(2)
        ->and($last->json('data.1'))->toMatchArray(['id' => null, 'type' => 'card.created', 'user' => ['id' => $user->id, 'name' => 'Sam']])
        ->and($last->json('next_page_url'))->toBeNull();
});

it('forbids reading history of a board you cannot access', function () {
    [, $board, $lead] = historyBoard();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'A', 'description' => '']);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/boards/{$board->id}/cards/{$card->id}/history")
        ->assertForbidden();
});
