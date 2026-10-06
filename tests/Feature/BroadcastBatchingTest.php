<?php

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Sprint;
use App\Infrastructure\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Multi-card operations must broadcast ONE batched BoardEvent, not one per card —
 * BoardEvent is ShouldBroadcastNow, so a per-card loop fires N blocking Reverb
 * pushes inside the request. broadcast() dispatches through the event bus, so
 * Event::fake(BoardEvent) observes it even with the 'null' broadcast driver.
 */
it('broadcasts exactly one cards.reordered event for a multi-card reorder', function () {
    Event::fake([BoardEvent::class]);

    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $doing = Section::create(['board_id' => $board->id, 'name' => 'Doing']);
    $a = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'A', 'description' => '', 'position' => 0]);
    $b = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'B', 'description' => '', 'position' => 1]);
    $c = Card::create(['board_id' => $board->id, 'section_id' => $doing->id, 'name' => 'C', 'description' => '', 'position' => 0]);

    // Move C to the top of To Do — all three cards get repositioned there.
    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/reorder", [
            'section_id' => $todo->id,
            'ordered_ids' => [$c->id, $a->id, $b->id],
        ])
        ->assertOk();

    $reordered = Event::dispatched(BoardEvent::class, fn (BoardEvent $e) => $e->type === 'cards.reordered');
    expect($reordered)->toHaveCount(1);

    // The single event carries a converging entry per card: id + the merge fields.
    $cards = collect($reordered->first()[0]->payload['cards']);
    expect($cards)->toHaveCount(3);
    expect($cards->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
    $entryC = $cards->firstWhere('id', $c->id);
    expect($entryC['section_id'])->toBe($todo->id);
    expect($entryC['position'])->toBe(0);
    expect($entryC)->toHaveKeys(['done_at', 'section_entered_at']);
    expect($cards->firstWhere('id', $b->id)['position'])->toBe(2);

    // No stragglers: the per-card card.updated fan-out is gone.
    expect(Event::dispatched(BoardEvent::class, fn (BoardEvent $e) => $e->type === 'card.updated'))->toHaveCount(0);
});

it('broadcasts exactly one cards.sprint_changed event when completing a sprint', function () {
    Event::fake([BoardEvent::class]);

    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Scrum', 'description' => '', 'type' => 'scrum']);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $sprint = Sprint::create(['board_id' => $board->id, 'name' => 'S1', 'status' => 'active', 'is_active' => true]);
    $open1 = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Open 1', 'description' => '', 'sprint_id' => $sprint->id]);
    $open2 = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Open 2', 'description' => '', 'sprint_id' => $sprint->id]);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sprints/{$sprint->id}/complete", ['move_to' => 'backlog'])
        ->assertOk();

    $changed = Event::dispatched(BoardEvent::class, fn (BoardEvent $e) => $e->type === 'cards.sprint_changed');
    expect($changed)->toHaveCount(1);

    // Both incomplete cards land in the backlog (sprint_id null) in one payload.
    $cards = collect($changed->first()[0]->payload['cards']);
    expect($cards->pluck('id')->sort()->values()->all())->toBe([$open1->id, $open2->id]);
    expect($cards->pluck('sprint_id')->unique()->all())->toBe([null]);

    // The old per-card card.updated fan-out is gone.
    expect(Event::dispatched(BoardEvent::class, fn (BoardEvent $e) => $e->type === 'card.updated'))->toHaveCount(0);
});

/**
 * The batched frame still has a ceiling: Reverb's HTTP publish endpoint returns
 * 413 "Payload too large" past REVERB_MAX_REQUEST_SIZE, which surfaces as
 * `BroadcastException: Pusher error: Payload too large.`. A 104-card column
 * serialized to ~16.6 KB and tripped the 10 KB default, so reorder() chunks.
 */
it('chunks cards.reordered so no frame can outgrow the broadcaster limit', function () {
    Event::fake([BoardEvent::class]);

    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Big', 'description' => '']);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do']);

    // 95 cards — past three chunks of 25, so the short tail chunk is exercised too.
    $ids = collect(range(1, 95))->map(fn ($i) => Card::create([
        'board_id' => $board->id, 'section_id' => $todo->id,
        'name' => "Card {$i}", 'description' => '', 'position' => $i - 1,
    ])->id)->all();

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/reorder", [
            'section_id' => $todo->id,
            'ordered_ids' => array_reverse($ids),
        ])
        ->assertOk();

    $events = Event::dispatched(BoardEvent::class, fn (BoardEvent $e) => $e->type === 'cards.reordered');
    expect($events)->toHaveCount(4); // 25 + 25 + 25 + 20

    // Every frame fits the stock 10 KB budget, measured the way Reverb measures it:
    // the JSON request body, with `data` carried as a stringified field.
    foreach ($events as $event) {
        $data = json_encode($event[0]->payload);
        $body = json_encode(['name' => 'board.event', 'data' => $data, 'channels' => ['private-board.'.$board->id]]);
        expect(strlen($body))->toBeLessThan(10_000);
    }

    // Chunking must not lose or duplicate a card: the union is the full reorder,
    // and each entry still carries the fields the client merges on.
    $all = collect($events)->flatMap(fn ($e) => $e[0]->payload['cards']);
    expect($all)->toHaveCount(95);
    expect($all->pluck('id')->all())->toBe(array_reverse($ids));
    expect($all->pluck('position')->all())->toBe(range(0, 94));
    expect($all->first())->toHaveKeys(['id', 'section_id', 'position', 'done_at', 'section_entered_at']);
});
