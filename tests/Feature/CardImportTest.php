<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Tag;
use App\Infrastructure\Models\User;

/** A board with two ordered columns; returns [$user, $board, $todo, $doing]. */
function importBoard(): array
{
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'Board', 'description' => '']);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do', 'order' => 0]);
    $doing = Section::create(['board_id' => $board->id, 'name' => 'In Progress', 'order' => 1]);

    return [$user, $board, $todo, $doing];
}

it('imports a bare array of cards into the first column', function () {
    [$user, $board, $todo] = importBoard();

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/import", [
            ['name' => 'First', 'description' => 'One'],
            ['title' => 'Second'], // alias for name
        ])
        ->assertCreated()
        ->assertJson(['created_count' => 2, 'error_count' => 0]);

    expect(Card::where('board_id', $board->id)->count())->toBe(2);
    expect(Card::where('name', 'First')->first()->section_id)->toBe($todo->id);
    expect(Card::where('name', 'Second')->first()->section_id)->toBe($todo->id);
});

it('accepts a { cards: [...] } envelope and a single object', function () {
    [$user, $board] = importBoard();

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/import", [
            'cards' => [['name' => 'Enveloped']],
        ])->assertCreated()->assertJson(['created_count' => 1]);

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/import", ['name' => 'Solo'])
        ->assertCreated()->assertJson(['created_count' => 1]);

    expect(Card::whereIn('name', ['Enveloped', 'Solo'])->count())->toBe(2);
});

it('routes a card to a column by name (case-insensitive) and maps fields', function () {
    [$user, $board, , $doing] = importBoard();

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/import", [
            [
                'name' => 'Rich',
                'column' => 'in progress',
                'priority' => 'HIGH',
                'due' => '2026-09-01',
                'points' => 5,
                'value' => '1200.50',
            ],
        ])->assertCreated()->assertJson(['created_count' => 1]);

    $card = Card::where('name', 'Rich')->first();
    expect($card->section_id)->toBe($doing->id);
    expect($card->priority)->toBe('high');
    expect($card->due_date->toDateString())->toBe('2026-09-01');
    expect($card->story_points)->toBe(5);
    expect((float) $card->value)->toBe(1200.50);
});

it('creates board tags on demand from string or array tags', function () {
    [$user, $board] = importBoard();

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/import", [
            ['name' => 'A', 'tags' => ['bug', 'urgent']],
            ['name' => 'B', 'labels' => 'bug, backend'], // comma string + reuse "bug"
        ])->assertCreated()->assertJson(['created_count' => 2]);

    // "bug" created once and reused; "urgent" + "backend" new → 3 distinct tags.
    expect(Tag::where('board_id', $board->id)->count())->toBe(3);
    expect(Card::where('name', 'A')->first()->tags)->toHaveCount(2);
    expect(Card::where('name', 'B')->first()->tags->pluck('name')->sort()->values()->all())
        ->toBe(['backend', 'bug']);
});

it('isolates bad rows and reports per-index errors without failing the batch', function () {
    [$user, $board] = importBoard();

    $res = $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/import", [
            ['name' => 'Good'],
            ['description' => 'no name here'],
            ['name' => 'Bad column', 'section' => 'Nonexistent'],
        ])->assertCreated();

    $res->assertJson(['created_count' => 1, 'error_count' => 2]);
    expect(Card::where('board_id', $board->id)->count())->toBe(1);
    $errors = $res->json('errors');
    expect($errors[0]['index'])->toBe(1);
    expect($errors[1]['index'])->toBe(2);
});

it('imports an Opportunity Canvas as a single card with a linked contact', function () {
    [$user, $board, $todo] = importBoard();

    $canvas = [
        'schemaVersion' => '1.1',
        'document' => ['code' => 'OC-2026-050'],
        'opportunity' => ['name' => 'Acme rollout', 'segment' => 'Logistics'],
        'contact' => ['name' => 'Jane Doe', 'emailOrPhone' => 'jane@acme.com'],
        'problem' => ['description' => 'Manual counts cause errors.'],
        'impact' => ['types' => ['Financial', 'Operational']],
        'commercialContext' => ['expectedBudget' => 'R$ 120.000,00', 'urgency' => 'Alta'],
        'objective' => ['desiredDeadline' => '2026-10-01'],
    ];

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/import", $canvas)
        ->assertCreated()
        ->assertJson(['created_count' => 1, 'error_count' => 0]);

    $card = Card::where('board_id', $board->id)->firstOrFail();
    expect($card->name)->toBe('Acme rollout');
    expect($card->section_id)->toBe($todo->id);        // default first column
    expect($card->priority)->toBe('high');             // from urgency
    expect((float) $card->value)->toBe(120000.0);      // pt-BR budget parsed
    expect($card->due_date->toDateString())->toBe('2026-10-01');
    expect($card->description)->toContain('<h3>Problem</h3>');

    $card->load('contact', 'tags');
    expect($card->contact?->email)->toBe('jane@acme.com');
    expect($card->tags->pluck('name'))->toContain('Financial', 'Operational', 'Logistics');
});

it('rejects a payload that is not a card shape', function () {
    [$user, $board] = importBoard();

    $this->actingAs($user)
        ->postJson("/api/boards/{$board->id}/cards/import", ['cards' => 'not an array'])
        ->assertStatus(422);

    expect(Card::count())->toBe(0);
});

it('refuses to import for a non-member', function () {
    [, $board] = importBoard();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->postJson("/api/boards/{$board->id}/cards/import", [['name' => 'X']])
        ->assertForbidden();

    expect(Card::count())->toBe(0);
});
