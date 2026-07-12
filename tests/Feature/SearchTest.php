<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

function seedSearch(User $u): void
{
    $project = Project::create(['owner_id' => $u->id, 'name' => 'Core', 'description' => '']);

    $board = Board::create(['user_id' => $u->id, 'project_id' => $project->id, 'name' => 'Marketing', 'description' => '', 'ticket_prefix' => 'MKT']);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do', 'order' => 0]);
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Launch plan', 'description' => '', 'ticket_number' => 42]);
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Fix bug', 'description' => '']);

    $crm = Board::create(['user_id' => $u->id, 'project_id' => $project->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm', 'currency' => 'USD']);
    $lead = Section::create(['board_id' => $crm->id, 'name' => 'Lead', 'order' => 0]);
    Card::create(['board_id' => $crm->id, 'section_id' => $lead->id, 'name' => 'Acme deal', 'description' => '', 'value' => 1000]);
}

it('requires authentication', function () {
    $this->getJson('/api/search?q=abc')->assertStatus(401);
});

it('returns nothing for queries shorter than two characters', function () {
    $u = User::factory()->create();
    seedSearch($u);

    $res = $this->actingAs($u)->getJson('/api/search?q=a')->assertOk();

    expect($res->json('boards'))->toBe([]);
    expect($res->json('cards'))->toBe([]);
});

it('finds boards, cards, CRM deals, and cards by ticket number', function () {
    $u = User::factory()->create();
    seedSearch($u);

    // board by name
    $res = $this->actingAs($u)->getJson('/api/search?q=Marketing')->assertOk();
    expect(collect($res->json('boards'))->pluck('name'))->toContain('Marketing');

    // card by name (+ ticket_key composed)
    $res = $this->actingAs($u)->getJson('/api/search?q=Launch')->assertOk();
    expect(collect($res->json('cards'))->pluck('name'))->toContain('Launch plan');
    expect($res->json('cards.0.ticket_key'))->toBe('MKT-42');
    expect($res->json('cards.0.is_deal'))->toBeFalse();

    // card by ticket number
    $res = $this->actingAs($u)->getJson('/api/search?q=42')->assertOk();
    expect(collect($res->json('cards'))->pluck('name'))->toContain('Launch plan');

    // CRM card flagged as a deal
    $res = $this->actingAs($u)->getJson('/api/search?q=Acme')->assertOk();
    expect($res->json('cards.0.name'))->toBe('Acme deal');
    expect($res->json('cards.0.is_deal'))->toBeTrue();
});

it('scopes results to the authenticated user', function () {
    $me = User::factory()->create();
    seedSearch($me);

    $other = User::factory()->create();
    $ob = Board::create(['user_id' => $other->id, 'name' => 'SecretBoard', 'description' => '']);
    $os = Section::create(['board_id' => $ob->id, 'name' => 'To Do', 'order' => 0]);
    Card::create(['board_id' => $ob->id, 'section_id' => $os->id, 'name' => 'Secret card', 'description' => '']);

    $res = $this->actingAs($me)->getJson('/api/search?q=Secret')->assertOk();

    expect($res->json('boards'))->toBe([]);
    expect($res->json('cards'))->toBe([]);
});
