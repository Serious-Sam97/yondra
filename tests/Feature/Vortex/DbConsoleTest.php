<?php

use App\Infrastructure\Models\User;

function dbAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('lists tables with row counts', function () {
    $admin = dbAdmin();

    $res = $this->actingAs($admin)->getJson('/api/vortex/db/tables')->assertOk();

    $users = collect($res->json('tables'))->firstWhere('name', 'users');
    expect($users)->not->toBeNull()
        ->and($users['rows'])->toBe(1);
});

it('browses rows of a real table and rejects bogus tables', function () {
    $admin = dbAdmin();

    $res = $this->actingAs($admin)->getJson('/api/vortex/db/tables/users/rows')->assertOk();
    expect($res->json('columns'))->toContain('id', 'email')
        ->and($res->json('data.0.email'))->toBe($admin->email);

    $this->actingAs($admin)->getJson('/api/vortex/db/tables/not_a_table/rows')->assertNotFound();
});

it('runs a select and always rolls back', function () {
    $admin = dbAdmin();

    $res = $this->actingAs($admin)
        ->postJson('/api/vortex/db/query', ['sql' => 'select id, email from users'])
        ->assertOk();

    expect($res->json('type'))->toBe('select')
        ->and($res->json('row_count'))->toBe(1)
        ->and($res->json('committed'))->toBeFalse()
        ->and($res->json('columns'))->toContain('email');
});

it('dry-runs a write by default without persisting', function () {
    $admin = dbAdmin();

    $res = $this->actingAs($admin)
        ->postJson('/api/vortex/db/query', ['sql' => "update users set name = 'Dry Run'"])
        ->assertOk();

    expect($res->json('type'))->toBe('write')
        ->and($res->json('affected'))->toBe(1)
        ->and($res->json('committed'))->toBeFalse()
        ->and($admin->fresh()->name)->not->toBe('Dry Run');
});

it('persists a write when commit is true', function () {
    $admin = dbAdmin();

    $this->actingAs($admin)
        ->postJson('/api/vortex/db/query', ['sql' => "update users set name = 'Committed'", 'commit' => true])
        ->assertOk()
        ->assertJsonPath('committed', true);

    expect($admin->fresh()->name)->toBe('Committed');
});

it('rejects multiple statements', function () {
    $admin = dbAdmin();

    $this->actingAs($admin)
        ->postJson('/api/vortex/db/query', ['sql' => 'select 1; drop table users'])
        ->assertStatus(422);

    expect(User::count())->toBe(1);
});

it('returns sql errors as 422 with the driver message', function () {
    $admin = dbAdmin();

    $this->actingAs($admin)
        ->postJson('/api/vortex/db/query', ['sql' => 'select * from definitely_not_a_table'])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});
