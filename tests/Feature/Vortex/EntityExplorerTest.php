<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

function explorerAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

function seedCards(User $owner, int $count = 3): array
{
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $cards = [];
    foreach (range(1, $count) as $i) {
        $cards[] = Card::create([
            'board_id' => $board->id,
            'section_id' => $section->id,
            'name' => "Card {$i}",
            'description' => '',
        ]);
    }

    return [$board, $section, $cards];
}

it('lists the entity registry with counts', function () {
    $admin = explorerAdmin();

    $res = $this->actingAs($admin)->getJson('/api/vortex/entities')->assertOk();

    $slugs = collect($res->json('entities'))->pluck('slug');
    expect($slugs)->toContain('users', 'boards', 'cards', 'files');

    $users = collect($res->json('entities'))->firstWhere('slug', 'users');
    expect($users['count'])->toBe(1);
});

it('paginates and searches an entity list', function () {
    $admin = explorerAdmin();
    User::factory()->create(['name' => 'Marina Kepler', 'email' => 'marina@example.com']);
    User::factory()->create(['name' => 'Davi Vortex', 'email' => 'davi@example.com']);

    $res = $this->actingAs($admin)
        ->getJson('/api/vortex/entities/users?q=marina')
        ->assertOk();

    expect($res->json('total'))->toBe(1)
        ->and($res->json('data.0.email'))->toBe('marina@example.com');
});

it('shows a record with drill-down counts', function () {
    $admin = explorerAdmin();
    [$board] = seedCards($admin, 2);

    $res = $this->actingAs($admin)
        ->getJson("/api/vortex/entities/boards/{$board->id}")
        ->assertOk();

    expect($res->json('record.cards_count'))->toBe(2)
        ->and($res->json('record.owner.id'))->toBe($admin->id)
        ->and($res->json('editable'))->toContain('name');
});

it('updates only editable fields', function () {
    $admin = explorerAdmin();
    [, , $cards] = seedCards($admin, 1);

    $this->actingAs($admin)
        ->putJson("/api/vortex/entities/cards/{$cards[0]->id}", ['name' => 'Renamed by Vortex'])
        ->assertOk();

    expect($cards[0]->fresh()->name)->toBe('Renamed by Vortex');
});

it('rejects non-editable fields with 422', function () {
    $admin = explorerAdmin();
    [, , $cards] = seedCards($admin, 1);

    $this->actingAs($admin)
        ->putJson("/api/vortex/entities/cards/{$cards[0]->id}", ['board_id' => 999])
        ->assertStatus(422);
});

it('deletes a record', function () {
    $admin = explorerAdmin();
    $victim = User::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/vortex/entities/users/{$victim->id}")
        ->assertNoContent();

    expect(User::find($victim->id))->toBeNull();
});

it('404s on an unknown entity', function () {
    $admin = explorerAdmin();

    $this->actingAs($admin)->getJson('/api/vortex/entities/nukes')->assertNotFound();
});
