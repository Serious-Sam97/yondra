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

it('marks auto-created and repository tags as custom', function () {
    $auto = $this->service->findOrCreateByName($this->board->id, 'Design');
    $created = $this->service->create(['board_id' => $this->board->id, 'name' => 'Urgent', 'color' => '#ff0000']);

    expect($auto->kind)->toBe('custom');
    expect($created->kind)->toBe('custom');
});

it('seeds the canonical channel tags for a board', function () {
    $this->service->seedChannelTags($this->board->id);

    $channels = Tag::where('board_id', $this->board->id)->where('kind', 'channel')->get();
    expect($channels)->toHaveCount(count(TagService::CHANNEL_TAGS));
    expect($channels->pluck('name')->all())
        ->toEqualCanonicalizing(['WhatsApp', 'Email', 'Phone', 'Instagram']);
    expect($channels->firstWhere('name', 'Email')->color)->toBe('#3b82f6');
});

it('does not duplicate channel tags when seeded twice', function () {
    $this->service->seedChannelTags($this->board->id);
    $this->service->seedChannelTags($this->board->id);

    expect(Tag::where('board_id', $this->board->id)->where('kind', 'channel')->count())
        ->toBe(count(TagService::CHANNEL_TAGS));
});

it('locks channel tag names but allows recolouring', function () {
    $this->service->seedChannelTags($this->board->id);
    $email = Tag::where('board_id', $this->board->id)->where('name', 'Email')->first();

    $updated = $this->service->edit($this->board->id, $email->id, ['name' => 'Newsletter', 'color' => '#000000']);

    expect($updated->name)->toBe('Email');       // rename ignored
    expect($updated->color)->toBe('#000000');     // recolour applied
});

it('refuses to delete a channel tag', function () {
    $this->service->seedChannelTags($this->board->id);
    $phone = Tag::where('board_id', $this->board->id)->where('name', 'Phone')->first();

    $this->service->remove($this->board->id, $phone->id);
})->throws(\Illuminate\Validation\ValidationException::class);

it('still deletes a custom tag', function () {
    $tag = $this->service->create(['board_id' => $this->board->id, 'name' => 'Temp', 'color' => '#123456']);

    $this->service->remove($this->board->id, $tag->id);

    expect(Tag::find($tag->id))->toBeNull();
});
