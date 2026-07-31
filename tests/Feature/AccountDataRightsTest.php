<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardChecklistItem;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Tag;
use App\Infrastructure\Models\User;
use Illuminate\Support\Facades\Hash;

// Build a board owned by $user with a card and a spread of child records, so
// export/delete tests exercise the real graph rather than a bare row.
function seedOwnedBoard(User $user): array
{
    $board = Board::create([
        'user_id' => $user->id,
        'name' => 'My Board',
        'description' => '',
        'type' => 'kanban',
    ]);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create([
        'board_id' => $board->id,
        'section_id' => $section->id,
        'name' => 'Secret card',
        'description' => 'private notes',
    ]);
    CardChecklistItem::create([
        'card_id' => $card->id,
        'text' => 'step one',
        'is_done' => false,
        'position' => 0,
    ]);
    CardComment::create([
        'card_id' => $card->id,
        'user_id' => $user->id,
        'body' => 'a private comment',
    ]);
    $tag = Tag::create(['board_id' => $board->id, 'name' => 'urgent', 'color' => '#ff0000']);
    $card->tags()->attach($tag->id);
    Contact::create(['board_id' => $board->id, 'name' => 'Client X', 'email' => 'x@client.com']);

    return compact('board', 'section', 'card');
}

it('exports the account data as a downloadable json', function () {
    $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
    seedOwnedBoard($user);

    $res = $this->actingAs($user)->getJson('/api/user/export')->assertOk();

    $res->assertJsonPath('account.email', 'ana@example.com')
        ->assertJsonPath('boards.0.name', 'My Board')
        ->assertJsonPath('boards.0.cards.0.name', 'Secret card')
        ->assertJsonPath('boards.0.cards.0.checklist_items.0.text', 'step one')
        ->assertJsonPath('boards.0.cards.0.comments.0.body', 'a private comment');

    expect($res->headers->get('content-disposition'))->toContain('attachment');
});

it('never leaks another account\'s boards into the export', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    seedOwnedBoard($other);

    $this->actingAs($me)->getJson('/api/user/export')
        ->assertOk()
        ->assertJsonCount(0, 'boards');
});

it('refuses to delete the account without the correct password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

    $this->actingAs($user)->deleteJson('/api/user', ['password' => 'wrong'])
        ->assertUnprocessable();

    expect(User::find($user->id))->not->toBeNull();
});

it('hard-deletes the account and everything it owns', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse')]);
    ['board' => $board, 'card' => $card] = seedOwnedBoard($user);

    $this->actingAs($user)->deleteJson('/api/user', ['password' => 'correct-horse'])
        ->assertOk();

    expect(User::find($user->id))->toBeNull();
    expect(Board::find($board->id))->toBeNull();
    expect(Card::find($card->id))->toBeNull();
    // Deep children cascade at the database level off the card/board delete.
    expect(CardComment::count())->toBe(0);
    expect(CardChecklistItem::count())->toBe(0);
    expect(Contact::count())->toBe(0);
    expect(Tag::count())->toBe(0);
});

it('keeps another account\'s card but nulls the deleted user\'s assignment', function () {
    $me = User::factory()->create(['password' => Hash::make('correct-horse')]);
    $other = User::factory()->create();
    ['card' => $otherCard] = seedOwnedBoard($other);
    $otherCard->update(['assigned_user_id' => $me->id]);

    $this->actingAs($me)->deleteJson('/api/user', ['password' => 'correct-horse'])
        ->assertOk();

    $otherCard->refresh();
    expect($otherCard->assigned_user_id)->toBeNull();
});
