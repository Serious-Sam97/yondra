<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

function vortexAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('returns global counts and system info', function () {
    $admin = vortexAdmin();
    $board = Board::create(['user_id' => $admin->id, 'name' => 'B1', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'C1', 'description' => '']);
    Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'C2', 'description' => '']);
    // An activity row forces the user/board eager-loads to actually resolve.
    BoardActivity::create(['board_id' => $board->id, 'user_id' => $admin->id, 'type' => 'card.created', 'description' => 'created C1']);

    $res = $this->actingAs($admin)->getJson('/api/vortex/overview')->assertOk();

    $res->assertJsonPath('recent_activity.0.board.name', 'B1')
        ->assertJsonPath('recent_activity.0.user.id', $admin->id)
        ->assertJsonPath('counts.users', 1)
        ->assertJsonPath('counts.boards', 1)
        ->assertJsonPath('counts.sections', 1)
        ->assertJsonPath('counts.cards', 2)
        ->assertJsonStructure([
            'counts' => ['users', 'projects', 'boards', 'cards', 'card_images', 'sprints', 'tokens'],
            'storage' => ['attachments_bytes', 'disk_public_bytes'],
            'recent_users',
            'recent_activity',
            'system' => ['php', 'laravel', 'db_driver', 'queue'],
        ]);
});

it('returns a zero-filled timeseries with one entry per day', function () {
    $admin = vortexAdmin();

    $res = $this->actingAs($admin)
        ->getJson('/api/vortex/overview/timeseries?days=7&metrics=users,cards')
        ->assertOk();

    $series = $res->json('series');
    expect($res->json('days'))->toBe(7)
        ->and($series['users'])->toHaveCount(7)
        ->and($series['cards'])->toHaveCount(7)
        // the admin user was created today — last bucket must count it
        ->and(end($series['users'])['count'])->toBe(1);
});

it('ignores unknown metrics and falls back to cards', function () {
    $admin = vortexAdmin();

    $res = $this->actingAs($admin)
        ->getJson('/api/vortex/overview/timeseries?days=3&metrics=bogus,nope')
        ->assertOk();

    expect(array_keys($res->json('series')))->toBe(['cards']);
});
