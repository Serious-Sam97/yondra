<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Tag;
use App\Infrastructure\Models\User;
use App\Services\TagService;

beforeEach(function () {
    $this->service = new TagService;
    $user = User::factory()->create();
    $this->board = Board::create(['user_id' => $user->id, 'name' => 'B', 'description' => '']);
});

it('creates a board-scoped tag with a colour when none exists', function () {
    $tag = $this->service->findOrCreateByName($this->board->id, 'Design');

    expect($tag)->toBeInstanceOf(Tag::class);
    expect($tag->board_id)->toBe($this->board->id);
    expect($tag->name)->toBe('Design');
    expect($tag->color)->toMatch('/^#[0-9a-f]{6}$/i');
    expect(Tag::where('board_id', $this->board->id)->count())->toBe(1);
});

it('reuses an existing tag instead of duplicating (case-insensitive)', function () {
    $first = $this->service->findOrCreateByName($this->board->id, 'Design');
    $again = $this->service->findOrCreateByName($this->board->id, 'design');

    expect($again->id)->toBe($first->id);
    expect(Tag::where('board_id', $this->board->id)->count())->toBe(1);
});

it('assigns the same name a deterministic colour', function () {
    $a = $this->service->findOrCreateByName($this->board->id, 'Marketing');
    $a->delete();
    $b = $this->service->findOrCreateByName($this->board->id, 'Marketing');

    expect($b->color)->toBe($a->color);
});

it('keeps tags separate across boards', function () {
    $other = Board::create(['user_id' => $this->board->user_id, 'name' => 'B2', 'description' => '']);

    $t1 = $this->service->findOrCreateByName($this->board->id, 'SEO');
    $t2 = $this->service->findOrCreateByName($other->id, 'SEO');

    expect($t1->id)->not->toBe($t2->id);
    expect(Tag::where('name', 'SEO')->count())->toBe(2);
});

it('truncates an over-long tag name to the column limit', function () {
    $tag = $this->service->findOrCreateByName($this->board->id, str_repeat('x', 80));
    expect(mb_strlen($tag->name))->toBe(50);
});
