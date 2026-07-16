<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

// A CRM board whose denominator (all non-archived cards) is 5:
//   A,B won Feb 2026 · C won Apr 2026 · D open · F won-but-out-of-range.
// Excluded from the denominator: E (archived). Excluded entirely: a done card
// on a non-CRM board.
function seedConversion(User $user): Board
{
    $project = Project::create(['owner_id' => $user->id, 'name' => 'Core', 'description' => '']);

    $crm = Board::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm', 'currency' => 'USD']);
    $stage = Section::create(['board_id' => $crm->id, 'name' => 'Won', 'order' => 0]);

    // Feb 2026: two wins.
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'name' => 'A', 'description' => '', 'done_at' => '2026-02-10 12:00:00']);
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'name' => 'B', 'description' => '', 'done_at' => '2026-02-20 12:00:00']);
    // Apr 2026: one win.
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'name' => 'C', 'description' => '', 'done_at' => '2026-04-05 12:00:00']);
    // Open deal — counts toward the denominator, never the numerator.
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'name' => 'D', 'description' => '']);
    // Archived — excluded from the denominator entirely.
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'name' => 'E', 'description' => '', 'archived_at' => '2026-03-01 12:00:00']);
    // Won before the queried range — in the denominator, but no in-range month.
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'name' => 'F', 'description' => '', 'done_at' => '2025-12-31 12:00:00']);

    // A done card on a NON-CRM board must not count anywhere.
    $kanban = Board::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => 'Dev', 'description' => '']);
    $done = Section::create(['board_id' => $kanban->id, 'name' => 'Done', 'order' => 0]);
    Card::create(['board_id' => $kanban->id, 'section_id' => $done->id, 'name' => 'Shipped', 'description' => '', 'done_at' => '2026-02-12 12:00:00']);

    return $crm;
}

it('requires authentication', function () {
    $this->getJson('/api/reports/conversion')->assertStatus(401);
});

it('divides monthly wins by the total cards on the CRM boards', function () {
    $user = User::factory()->create();
    seedConversion($user);

    $res = $this->actingAs($user)
        ->getJson('/api/reports/conversion?from=2026-01&to=2026-06')
        ->assertOk();

    expect($res->json('has_crm'))->toBeTrue();
    expect($res->json('from'))->toBe('2026-01');
    expect($res->json('to'))->toBe('2026-06');

    // Contiguous Jan..Jun skeleton, oldest -> newest.
    expect($res->json('months'))->toHaveCount(6);

    // Denominator = 5 non-archived CRM cards (A,B,C,D,F); same for every month.
    expect($res->json('total_cards'))->toBe(5);
    expect($res->json('months.1.total'))->toBe(5);

    // Jan: no wins.
    expect($res->json('months.0.won'))->toBe(0);
    expect((float) $res->json('months.0.rate'))->toBe(0.0);

    // Feb: 2 wins / 5 = 0.4.
    expect($res->json('months.1.won'))->toBe(2);
    expect((float) $res->json('months.1.rate'))->toBe(0.4);

    // Apr: 1 win / 5 = 0.2.
    expect($res->json('months.3.won'))->toBe(1);
    expect((float) $res->json('months.3.rate'))->toBe(0.2);

    // Range totals: 3 in-range wins / 5 = 0.6.
    expect($res->json('total_won'))->toBe(3);
    expect((float) $res->json('conversion_rate'))->toBe(0.6);
});

it('defaults to the last twelve months when no range is given', function () {
    $user = User::factory()->create();
    seedConversion($user);

    $res = $this->actingAs($user)->getJson('/api/reports/conversion')->assertOk();

    expect($res->json('months'))->toHaveCount(12);
});

it('excludes CRM boards the user cannot see', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    seedConversion($other);

    $res = $this->actingAs($me)
        ->getJson('/api/reports/conversion?from=2026-01&to=2026-06')
        ->assertOk();

    expect($res->json('has_crm'))->toBeFalse();
    expect($res->json('total_won'))->toBe(0);
    expect($res->json('total_cards'))->toBe(0);
    expect((float) $res->json('conversion_rate'))->toBe(0.0);
    expect($res->json('months'))->toHaveCount(6);
});

it('rejects a malformed month parameter', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/reports/conversion?from=2026-13')
        ->assertStatus(422);
});
