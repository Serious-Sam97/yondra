<?php

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexMemory;
use App\Services\Vortex\MemoryService;
use Illuminate\Support\Facades\Http;

function memConfigure(string $reply): void
{
    config([
        'services.ai.driver' => 'anthropic',
        'services.ai.anthropic.api_key' => 'sk-test',
        'services.ai.anthropic.base_url' => 'https://api.anthropic.com',
        'services.ai.anthropic.version' => '2023-06-01',
        'services.ai.anthropic.model' => 'claude-opus-4-8',
    ]);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $reply]]], 200)]);
}

it('files facts from a chat turn and drops sensitive ones and duplicates', function () {
    memConfigure(json_encode(['facts' => [
        ['category' => 'life', 'fact' => 'Has a cat called Pixel'],
        ['category' => 'life', 'fact' => 'Takes medication for anxiety'],
        ['category' => 'nonsense', 'fact' => 'x'],
    ]]));
    $u = User::factory()->create();
    $svc = app(MemoryService::class);
    $svc->extract($u->id, 'my cat pixel walked on the keyboard again', 'ugh');
    $svc->extract($u->id, 'my cat pixel walked on the keyboard again', 'ugh');

    expect(VortexMemory::where('user_id', $u->id)->pluck('fact')->all())->toBe(['Has a cat called Pixel']);
});

it('recalls relevant facts first', function () {
    $u = User::factory()->create();
    VortexMemory::create(['user_id' => $u->id, 'category' => 'work', 'fact' => 'Works in marketing']);
    VortexMemory::create(['user_id' => $u->id, 'category' => 'life', 'fact' => 'Has a cat called Pixel']);
    $facts = app(MemoryService::class)->recall($u->id, 'what about the marketing launch?');
    expect($facts[0])->toBe('Works in marketing')->and($facts)->toHaveCount(2);
});

it('lets the user read and delete the dossier, only their own', function () {
    $u = User::factory()->create();
    $other = User::factory()->create();
    $mine = VortexMemory::create(['user_id' => $u->id, 'category' => 'work', 'fact' => 'Works in marketing']);
    $theirs = VortexMemory::create(['user_id' => $other->id, 'category' => 'work', 'fact' => 'Secret']);

    $this->actingAs($u)->getJson('/api/mascot/memories')->assertOk()->assertJsonCount(1)->assertJsonPath('0.fact', 'Works in marketing');
    $this->actingAs($u)->deleteJson("/api/mascot/memories/{$theirs->id}")->assertOk()->assertJson(['forgotten' => 0]);
    $this->actingAs($u)->deleteJson("/api/mascot/memories/{$mine->id}")->assertOk()->assertJson(['forgotten' => 1]);
    expect(VortexMemory::find($theirs->id))->not->toBeNull();
});
