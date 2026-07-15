<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

// A CRM board with won deals spread across Feb + Apr 2026, plus rows that must
// NOT show up: an open deal, a non-CRM done card, and an out-of-range win.
function seedRevenue(User $user): Board
{
    $project = Project::create(['owner_id' => $user->id, 'name' => 'Core', 'description' => '']);

    $crm = Board::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm', 'currency' => 'USD']);
    $stage = Section::create(['board_id' => $crm->id, 'name' => 'Won', 'order' => 0]);

    $acme = Contact::create(['board_id' => $crm->id, 'name' => 'Acme']);
    $globex = Contact::create(['board_id' => $crm->id, 'name' => 'Globex']);
    $initech = Contact::create(['board_id' => $crm->id, 'name' => 'Initech']);

    // Feb 2026: two wins for the SAME client -> revenue 15000, 2 quotes, 1 client.
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'contact_id' => $acme->id, 'name' => 'Acme #1', 'description' => '', 'value' => 10000, 'done_at' => '2026-02-10 12:00:00']);
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'contact_id' => $acme->id, 'name' => 'Acme #2', 'description' => '', 'value' => 5000, 'done_at' => '2026-02-20 12:00:00']);

    // Apr 2026: one valued win + one won-with-no-value -> revenue 8000, 2 quotes, 2 clients.
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'contact_id' => $globex->id, 'name' => 'Globex', 'description' => '', 'value' => 8000, 'done_at' => '2026-04-05 12:00:00']);
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'contact_id' => $initech->id, 'name' => 'Initech (no value)', 'description' => '', 'done_at' => '2026-04-15 12:00:00']);

    // Excluded: still-open deal (no done_at).
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'name' => 'Open pipeline', 'description' => '', 'value' => 99999]);
    // Excluded: win before the queried range.
    Card::create(['board_id' => $crm->id, 'section_id' => $stage->id, 'name' => 'Last year', 'description' => '', 'value' => 1234, 'done_at' => '2025-12-31 12:00:00']);

    // Excluded: a done card on a NON-CRM board must not count as revenue.
    $kanban = Board::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => 'Dev', 'description' => '']);
    $done = Section::create(['board_id' => $kanban->id, 'name' => 'Done', 'order' => 0]);
    Card::create(['board_id' => $kanban->id, 'section_id' => $done->id, 'name' => 'Shipped', 'description' => '', 'value' => 7777, 'done_at' => '2026-02-12 12:00:00']);

    return $crm;
}

it('requires authentication', function () {
    $this->getJson('/api/reports/revenue')->assertStatus(401);
});

it('buckets won revenue by month with quote and distinct-client counts', function () {
    $user = User::factory()->create();
    seedRevenue($user);

    $res = $this->actingAs($user)
        ->getJson('/api/reports/revenue?from=2026-01&to=2026-06')
        ->assertOk();

    expect($res->json('currency'))->toBe('USD');
    expect($res->json('from'))->toBe('2026-01');
    expect($res->json('to'))->toBe('2026-06');

    // Contiguous Jan..Jun skeleton, oldest -> newest, gaps zero-filled.
    expect($res->json('months'))->toHaveCount(6);
    expect($res->json('months.0.month'))->toBe('2026-01');
    expect($res->json('months.5.month'))->toBe('2026-06');

    // Jan: empty.
    expect((float) $res->json('months.0.revenue'))->toBe(0.0);
    expect($res->json('months.0.count'))->toBe(0);
    expect($res->json('months.0.clients'))->toBe(0);

    // Feb: two wins, same client.
    expect((float) $res->json('months.1.revenue'))->toBe(15000.0);
    expect($res->json('months.1.count'))->toBe(2);
    expect($res->json('months.1.clients'))->toBe(1);

    // Apr: valued win + no-value win -> 2 quotes, 2 clients, 8000 revenue.
    expect((float) $res->json('months.3.revenue'))->toBe(8000.0);
    expect($res->json('months.3.count'))->toBe(2);
    expect($res->json('months.3.clients'))->toBe(2);

    // Range totals: distinct clients across the whole window = Acme, Globex, Initech.
    expect((float) $res->json('total_revenue'))->toBe(23000.0);
    expect($res->json('total_count'))->toBe(4);
    expect($res->json('total_clients'))->toBe(3);
});

it('defaults to the last twelve months when no range is given', function () {
    $user = User::factory()->create();
    seedRevenue($user);

    $res = $this->actingAs($user)->getJson('/api/reports/revenue')->assertOk();

    expect($res->json('months'))->toHaveCount(12);
});

it('excludes CRM boards the user cannot see', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    // Only the other user has a CRM board with a win.
    seedRevenue($other);

    $res = $this->actingAs($me)
        ->getJson('/api/reports/revenue?from=2026-01&to=2026-06')
        ->assertOk();

    // No accessible CRM board -> null currency, zeroed totals, skeleton intact.
    expect($res->json('currency'))->toBeNull();
    expect((float) $res->json('total_revenue'))->toBe(0.0);
    expect($res->json('total_count'))->toBe(0);
    expect($res->json('total_clients'))->toBe(0);
    expect($res->json('months'))->toHaveCount(6);
});

it('rejects a malformed month parameter', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/reports/revenue?from=2026-13')
        ->assertStatus(422);
});
