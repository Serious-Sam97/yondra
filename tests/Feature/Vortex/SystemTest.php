<?php

use App\Infrastructure\Models\User;

function systemAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('returns system info', function () {
    $admin = systemAdmin();

    $this->actingAs($admin)
        ->getJson('/api/vortex/system')
        ->assertOk()
        ->assertJsonStructure([
            'php', 'laravel', 'env', 'debug',
            'drivers' => ['db', 'queue', 'cache', 'session'],
            'logs_bytes', 'server_time',
        ]);
});

it('reports queue health', function () {
    $admin = systemAdmin();

    $res = $this->actingAs($admin)->getJson('/api/vortex/system/queue')->assertOk();

    expect($res->json('pending_total'))->toBe(0)
        ->and($res->json('failed_total'))->toBe(0);
});

it('clears selected caches', function () {
    $admin = systemAdmin();

    $this->actingAs($admin)
        ->postJson('/api/vortex/system/cache/clear', ['targets' => ['cache', 'view']])
        ->assertOk()
        ->assertJsonStructure(['results' => ['cache', 'view']]);
});

it('rejects unknown cache targets', function () {
    $admin = systemAdmin();

    $this->actingAs($admin)
        ->postJson('/api/vortex/system/cache/clear', ['targets' => ['rm-rf']])
        ->assertStatus(422);
});

it('tails a log file', function () {
    $admin = systemAdmin();
    file_put_contents(storage_path('logs/vortex-test.log'), implode("\n", range(1, 500))."\n");

    $res = $this->actingAs($admin)
        ->getJson('/api/vortex/system/logs?file=vortex-test.log&lines=100')
        ->assertOk();

    $lines = $res->json('lines');
    expect($lines)->toHaveCount(100)
        ->and(end($lines))->toBe('500');

    unlink(storage_path('logs/vortex-test.log'));
});

it('blocks path traversal in the log viewer', function () {
    $admin = systemAdmin();

    // basename() reduces this to ".env", which is not a file in logs/ → 404.
    $this->actingAs($admin)
        ->getJson('/api/vortex/system/logs?file=../../.env')
        ->assertNotFound();
});

it('reports storage usage with orphan detection', function () {
    $admin = systemAdmin();

    $this->actingAs($admin)
        ->getJson('/api/vortex/system/storage')
        ->assertOk()
        ->assertJsonStructure(['dirs', 'orphans' => ['count', 'size_bytes', 'sample']]);
});
