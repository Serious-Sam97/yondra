<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

it('resolves chips the caller can see and nulls the rest', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $mine = Board::create(['user_id' => $me->id, 'name' => 'Mine', 'description' => '', 'type' => 'kanban']);
    $theirs = Board::create(['user_id' => $other->id, 'name' => 'Theirs', 'description' => '', 'type' => 'kanban']);
    $s1 = Section::create(['board_id' => $mine->id, 'name' => 'To Do']);
    $s2 = Section::create(['board_id' => $theirs->id, 'name' => 'To Do']);
    $c1 = Card::create(['board_id' => $mine->id, 'section_id' => $s1->id, 'name' => 'Fix login', 'description' => '']);
    $c2 = Card::create(['board_id' => $theirs->id, 'section_id' => $s2->id, 'name' => 'Secret', 'description' => '']);

    $this->actingAs($me)->postJson('/api/mascot/resolve', ['refs' => [
        ['type' => 'card', 'id' => $c1->id],
        ['type' => 'card', 'id' => $c2->id],
        ['type' => 'board', 'id' => $mine->id],
        ['type' => 'board', 'id' => $theirs->id],
    ]])->assertOk()
        ->assertJsonPath("refs.card:{$c1->id}.name", 'Fix login')
        ->assertJsonPath("refs.card:{$c1->id}.board_id", $mine->id)
        ->assertJsonPath("refs.card:{$c2->id}", null)
        ->assertJsonPath("refs.board:{$mine->id}.name", 'Mine')
        ->assertJsonPath("refs.board:{$theirs->id}", null);

    $this->actingAs($me)->postJson('/api/mascot/resolve', ['refs' => [['type' => 'user', 'id' => 1]]])->assertStatus(422);
});
