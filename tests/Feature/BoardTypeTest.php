<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Sprint;
use App\Infrastructure\Models\User;

it('creates a crm board seeded with funnel stages', function () {
    $user = User::factory()->create();

    $res = $this->actingAs($user)
        ->postJson('/api/boards', ['name' => 'Sales', 'description' => '', 'type' => 'crm', 'currency' => 'BRL'])
        ->assertCreated()
        ->assertJsonFragment(['type' => 'crm', 'currency' => 'BRL']);

    $boardId = $res->json('id');
    $names = Section::where('board_id', $boardId)->orderBy('order')->pluck('name')->all();
    expect($names)->toBe(['Lead In', 'Contact Made', 'Proposal Made', 'Negotiations Started', 'Won']);
});

it('defaults a board to kanban with the classic lanes', function () {
    $user = User::factory()->create();

    $res = $this->actingAs($user)
        ->postJson('/api/boards', ['name' => 'Work', 'description' => ''])
        ->assertCreated()
        ->assertJsonFragment(['type' => 'kanban']);

    $names = Section::where('board_id', $res->json('id'))->orderBy('order')->pluck('name')->all();
    expect($names)->toBe(['To Do', 'In Progress', 'Done']);
});

it('stores a deal value on a card', function () {
    $user    = User::factory()->create();
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'Lead In']);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards", [
            'section_id' => $section->id,
            'name'       => 'Big client',
            'value'      => 12000.50,
        ])
        ->assertCreated()
        ->assertJsonFragment(['value' => '12000.50']);

    expect((float) Card::where('board_id', $board->id)->value('value'))->toBe(12000.50);
});

it('stamps section_entered_at on create and refreshes it on stage change', function () {
    $user     = User::factory()->create();
    $board    = Board::create(['user_id' => $user->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm']);
    $sectionA = Section::create(['board_id' => $board->id, 'name' => 'Lead In']);
    $sectionB = Section::create(['board_id' => $board->id, 'name' => 'Won']);

    $card = Card::create([
        'board_id' => $board->id, 'section_id' => $sectionA->id, 'name' => 'Deal', 'description' => '',
        'section_entered_at' => now()->subDays(3),
    ]);

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $sectionB->id])
        ->assertOk();

    $card->refresh();
    expect($card->section_id)->toBe($sectionB->id)
        ->and($card->section_entered_at->diffInHours(now()))->toBeLessThan(1);
});

it('keeps section_entered_at when the card stays in the same stage', function () {
    $user    = User::factory()->create();
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'Lead In']);
    $entered = now()->subDays(2);
    $card    = Card::create([
        'board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Deal', 'description' => '',
        'section_entered_at' => $entered,
    ]);

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['name' => 'Renamed deal'])
        ->assertOk();

    $card->refresh();
    expect($card->section_entered_at->timestamp)->toBe($entered->timestamp);
});

it('configures per-stage aging hours', function () {
    $user    = User::factory()->create();
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'Lead In']);

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/sections/{$section->id}", ['aging_hours' => 24])
        ->assertOk()
        ->assertJsonFragment(['aging_hours' => 24]);

    expect(Section::find($section->id)->aging_hours)->toBe(24);
});

it('marks a card done when it enters the configured done/won column', function () {
    $user  = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm']);
    $lead  = Section::create(['board_id' => $board->id, 'name' => 'Lead In']);
    $won   = Section::create(['board_id' => $board->id, 'name' => 'Won']);

    // Configure "Won" as the done column.
    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}", ['done_section_id' => $won->id])
        ->assertOk()
        ->assertJsonFragment(['done_section_id' => $won->id]);

    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Deal', 'description' => '']);

    // Moving into Won stamps done_at even though the column isn't named "Done".
    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $won->id])
        ->assertOk();
    expect($card->refresh()->done_at)->not->toBeNull();

    // Moving back out clears it.
    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $lead->id])
        ->assertOk();
    expect($card->refresh()->done_at)->toBeNull();
});

it('does not mark a card done in a plain Won column when none is configured', function () {
    $user  = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm']);
    $lead  = Section::create(['board_id' => $board->id, 'name' => 'Lead In']);
    $won   = Section::create(['board_id' => $board->id, 'name' => 'Won']);
    $card  = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Deal', 'description' => '']);

    // No done_section_id set + column isn't named "Done" → not done.
    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $won->id])
        ->assertOk();
    expect($card->refresh()->done_at)->toBeNull();
});

it('falls back to a "Done"-named column when no done column is configured', function () {
    $user  = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Work', 'description' => '', 'type' => 'kanban']);
    $todo  = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $done  = Section::create(['board_id' => $board->id, 'name' => 'Done']);
    $card  = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Task', 'description' => '']);

    $this->actingAs($user)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $done->id])
        ->assertOk();
    expect($card->refresh()->done_at)->not->toBeNull();
});

it('creates sprints in the backlog and deletes them', function () {
    // Full start/complete lifecycle lives in SprintLifecycleTest; this covers basic CRUD.
    $user  = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Scrum', 'description' => '', 'type' => 'scrum']);

    $s1 = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sprints", ['name' => 'Sprint 1'])
        ->assertCreated()
        ->assertJsonFragment(['status' => 'future'])
        ->json('id');

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/sprints", ['name' => 'Sprint 2'])
        ->assertCreated();

    $this->actingAs($user)
        ->deleteJson("/api/boards/{$board->id}/sprints/{$s1}")
        ->assertNoContent();

    expect(Sprint::where('board_id', $board->id)->count())->toBe(1);
});

it('assigns a card to a sprint with story points', function () {
    $user    = User::factory()->create();
    $board   = Board::create(['user_id' => $user->id, 'name' => 'Scrum', 'description' => '', 'type' => 'scrum']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $sprint  = Sprint::create(['board_id' => $board->id, 'name' => 'Sprint 1', 'is_active' => true]);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards", [
            'section_id'   => $section->id,
            'name'         => 'Story',
            'story_points' => 5,
            'sprint_id'    => $sprint->id,
        ])
        ->assertCreated()
        ->assertJsonFragment(['story_points' => 5, 'sprint_id' => $sprint->id]);
});
