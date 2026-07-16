<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

/** A CRM board whose cards can be stamped lost for the report. */
function crmReportBoard(User $owner): array
{
    $board = Board::create([
        'user_id' => $owner->id, 'name' => 'Pipeline', 'description' => '',
        'type' => 'crm', 'currency' => 'USD',
    ]);
    $lead = Section::create(['board_id' => $board->id, 'name' => 'Lead In', 'order' => 0]);

    return [$board, $lead];
}

it('buckets lost deals by month and breaks them down by reason', function () {
    $owner = User::factory()->create();
    [$board, $lead] = crmReportBoard($owner);

    $mk = fn (string $reason, float $value, string $lostAt) => Card::create([
        'board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Deal', 'description' => '', 'position' => 0,
        'value' => $value, 'loss_reason' => $reason, 'lost_at' => $lostAt,
    ]);
    $mk('Too expensive', 1000, now()->toDateTimeString());
    $mk('Too expensive', 500, now()->toDateTimeString());
    $mk('No response', 200, now()->toDateTimeString());
    // A won card (done_at, no lost_at) must NOT appear in the loss report.
    Card::create([
        'board_id' => $board->id, 'section_id' => $lead->id, 'name' => 'Won deal', 'description' => '', 'position' => 1,
        'value' => 9999, 'done_at' => now()->toDateTimeString(),
    ]);

    $res = $this->actingAs($owner)->getJson('/api/reports/loss')->assertOk();

    expect($res->json('currency'))->toBe('USD');
    expect($res->json('total_count'))->toBe(3);
    expect($res->json('total_lost_value'))->toEqual(1700.0);

    // Reasons ranked by frequency: "Too expensive" (2) leads "No response" (1).
    $reasons = $res->json('reasons');
    expect($reasons[0]['reason'])->toBe('Too expensive');
    expect($reasons[0]['count'])->toBe(2);
    expect($reasons[0]['value'])->toEqual(1500.0);
    expect($reasons[1]['reason'])->toBe('No response');

    // The month skeleton includes the current month with the right count.
    $thisMonth = now()->format('Y-m');
    $month = collect($res->json('months'))->firstWhere('month', $thisMonth);
    expect($month['count'])->toBe(3);
    expect($month['lost_value'])->toEqual(1700.0);
});

it('returns an empty report when the user has no CRM board', function () {
    $owner = User::factory()->create();
    // A non-CRM board should be ignored entirely.
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Kanban', 'description' => '', 'type' => 'kanban']);

    $res = $this->actingAs($owner)->getJson('/api/reports/loss')->assertOk();

    expect($res->json('currency'))->toBeNull();
    expect($res->json('total_count'))->toBe(0);
    expect($res->json('reasons'))->toBe([]);
});
