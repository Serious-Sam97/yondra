<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

/** A CRM board with a designated Lost stage + a configured reason list. */
function crmLossBoard(User $owner): array
{
    $board = Board::create([
        'user_id' => $owner->id,
        'name' => 'Pipeline',
        'description' => '',
        'type' => 'crm',
        'currency' => 'USD',
        'loss_reasons' => ['Too expensive', 'No response', 'Other'],
    ]);
    $lead = Section::create(['board_id' => $board->id, 'name' => 'Lead In', 'order' => 0]);
    $lost = Section::create(['board_id' => $board->id, 'name' => 'Lost', 'order' => 1]);
    $board->update(['lost_section_id' => $lost->id]);

    return [$board, $lead, $lost];
}

it('blocks moving a deal to Lost without a reason (reorder)', function () {
    $owner = User::factory()->create();
    [$board, $lead, $lost] = crmLossBoard($owner);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Deal', 'description' => '', 'position' => 0]);

    $res = $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/reorder", [
            'ordered_ids' => [$card->id],
            'section_id' => $lost->id,
        ])
        ->assertStatus(422);

    expect($res->json('error'))->toBe('loss_reason_required');
    expect($res->json('reasons'))->toContain('Too expensive');
    // The move did not happen.
    expect($card->fresh()->section_id)->toBe($lead->id);
    expect($card->fresh()->lost_at)->toBeNull();
});

it('rejects a reason that is not in the board list', function () {
    $owner = User::factory()->create();
    [$board, $lead, $lost] = crmLossBoard($owner);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Deal', 'description' => '', 'position' => 0]);

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/reorder", [
            'ordered_ids' => [$card->id],
            'section_id' => $lost->id,
            'loss_reason' => 'Made up reason',
        ])
        ->assertStatus(422);

    expect($card->fresh()->section_id)->toBe($lead->id);
});

it('moves to Lost with a valid reason and stamps lost_at (reorder)', function () {
    $owner = User::factory()->create();
    [$board, $lead, $lost] = crmLossBoard($owner);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Deal', 'description' => '', 'position' => 0]);

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/reorder", [
            'ordered_ids' => [$card->id],
            'section_id' => $lost->id,
            'loss_reason' => 'Too expensive',
        ])
        ->assertOk();

    $card->refresh();
    expect($card->section_id)->toBe($lost->id);
    expect($card->loss_reason)->toBe('Too expensive');
    expect($card->lost_at)->not->toBeNull();
    // A lost deal is not won.
    expect($card->done_at)->toBeNull();
});

it('requires a reason on the card update path too', function () {
    $owner = User::factory()->create();
    [$board, $lead, $lost] = crmLossBoard($owner);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Deal', 'description' => '', 'position' => 0]);

    // No reason → blocked.
    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['section_id' => $lost->id])
        ->assertStatus(422)
        ->assertJson(['error' => 'loss_reason_required']);

    // With a reason → persists.
    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", [
            'section_id' => $lost->id,
            'loss_reason' => 'No response',
        ])
        ->assertOk();

    $card->refresh();
    expect($card->loss_reason)->toBe('No response');
    expect($card->lost_at)->not->toBeNull();
});

it('clears lost_at and reason when a deal leaves the Lost stage', function () {
    $owner = User::factory()->create();
    [$board, $lead, $lost] = crmLossBoard($owner);
    $card = Card::create([
        'board_id' => $board->id, 'section_id' => $lost->id, 'name' => 'Deal', 'description' => '', 'position' => 0,
        'lost_at' => now(), 'loss_reason' => 'Too expensive',
    ]);

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/reorder", [
            'ordered_ids' => [$card->id],
            'section_id' => $lead->id,
        ])
        ->assertOk();

    $card->refresh();
    expect($card->section_id)->toBe($lead->id);
    expect($card->lost_at)->toBeNull();
    expect($card->loss_reason)->toBeNull();
});

it('does not gate non-CRM / non-Lost moves', function () {
    $owner = User::factory()->create();
    [$board, $lead, $lost] = crmLossBoard($owner);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $lost->id, 'name' => 'Deal', 'description' => '', 'position' => 0]);

    // Moving between two non-Lost sections needs no reason.
    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/reorder", [
            'ordered_ids' => [$card->id],
            'section_id' => $lead->id,
        ])
        ->assertOk();
});

it('seeds a Lost stage + default reasons on a new CRM board', function () {
    $owner = User::factory()->create();

    $res = $this->actingAs($owner)
        ->postJson('/api/boards', ['name' => 'Sales', 'description' => '', 'type' => 'crm'])
        ->assertCreated();

    $board = Board::find($res->json('id'));
    expect($board->lost_section_id)->not->toBeNull();
    expect($board->loss_reasons)->toBe(Board::DEFAULT_LOSS_REASONS);
    expect(Section::where('board_id', $board->id)->where('name', 'Lost')->exists())->toBeTrue();
});
