<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

/** An owned board with two columns to map onto roadmap steps. */
function roadmapBoard(User $owner): Board
{
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Flow', 'description' => '']);
    Section::create(['board_id' => $board->id, 'name' => 'To Do', 'order' => 0]);
    Section::create(['board_id' => $board->id, 'name' => 'Done', 'order' => 1]);

    return $board;
}

it('persists a valid roadmap flowchart and clears it with null', function () {
    $owner = User::factory()->create();
    $board = roadmapBoard($owner);
    [$a, $b] = $board->sections()->orderBy('order')->pluck('id')->all();

    $config = [
        'nodes' => [
            ['section_id' => $a, 'x' => 40, 'y' => 40],
            ['section_id' => $b, 'x' => 300, 'y' => 40],
        ],
        'edges' => [['from' => $a, 'to' => $b]],
    ];

    $res = $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}", ['roadmap_config' => $config])
        ->assertOk();

    expect($res->json('roadmap_config.nodes'))->toHaveCount(2);
    expect($res->json('roadmap_config.edges.0.from'))->toBe($a);

    $board->refresh();
    expect($board->roadmap_config['edges'][0]['to'])->toBe($b);

    // Null clears it back to auto-layout.
    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}", ['roadmap_config' => null])
        ->assertOk();
    expect($board->fresh()->roadmap_config)->toBeNull();
});

it('rejects a node that maps to a section on another board', function () {
    $owner = User::factory()->create();
    $board = roadmapBoard($owner);
    $a = $board->sections()->first()->id;

    // A section belonging to a different board must not be mappable.
    $other = roadmapBoard($owner);
    $foreign = $other->sections()->first()->id;

    $this->actingAs($owner)->putJson("/api/boards/{$board->id}", [
        'roadmap_config' => [
            'nodes' => [
                ['section_id' => $a, 'x' => 10, 'y' => 10],
                ['section_id' => $foreign, 'x' => 200, 'y' => 10],
            ],
            'edges' => [],
        ],
    ])->assertStatus(422);
});

it('surfaces the saved roadmap on the full board payload', function () {
    $owner = User::factory()->create();
    $board = roadmapBoard($owner);
    $a = $board->sections()->first()->id;

    $this->actingAs($owner)->putJson("/api/boards/{$board->id}", [
        'roadmap_config' => [
            'nodes' => [['section_id' => $a, 'x' => 5, 'y' => 5]],
            'edges' => [],
        ],
    ])->assertOk();

    $show = $this->actingAs($owner)->getJson("/api/boards/{$board->id}")->assertOk();
    expect($show->json('roadmap_config.nodes.0.section_id'))->toBe($a);
});
