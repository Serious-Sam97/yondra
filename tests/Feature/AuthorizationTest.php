<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardChecklistItem;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Tag;
use App\Infrastructure\Models\User;

/**
 * Cross-tenant scenarios: the attacker owns their own board (write access passes)
 * and supplies child-resource ids belonging to the victim's board.
 */
function crossTenantSetup(): array
{
    $attacker = User::factory()->create();
    $attackerBoard = Board::create(['user_id' => $attacker->id, 'name' => 'Attacker board', 'description' => '']);
    $attackerSection = Section::create(['board_id' => $attackerBoard->id, 'name' => 'To Do']);

    $victim = User::factory()->create();
    $victimBoard = Board::create(['user_id' => $victim->id, 'name' => 'Victim board', 'description' => '']);
    $victimSection = Section::create(['board_id' => $victimBoard->id, 'name' => 'Private', 'order' => 5]);
    $victimCard = Card::create(['board_id' => $victimBoard->id, 'section_id' => $victimSection->id, 'name' => 'Secret task', 'description' => '']);

    return compact('attacker', 'attackerBoard', 'attackerSection', 'victim', 'victimBoard', 'victimSection', 'victimCard');
}

it('rejects renaming a section that belongs to another board', function () {
    extract(crossTenantSetup());

    $this->actingAs($attacker)
        ->putJson("/api/boards/{$attackerBoard->id}/sections/{$victimSection->id}", ['name' => 'Hijacked'])
        ->assertNotFound();

    expect($victimSection->fresh()->name)->toBe('Private');
});

it('rejects deleting a section (and its cards) that belongs to another board', function () {
    extract(crossTenantSetup());

    $this->actingAs($attacker)
        ->deleteJson("/api/boards/{$attackerBoard->id}/sections/{$victimSection->id}")
        ->assertNotFound();

    expect(Section::find($victimSection->id))->not->toBeNull();
    expect(Card::find($victimCard->id))->not->toBeNull();
});

it('does not reorder sections belonging to another board', function () {
    extract(crossTenantSetup());
    $originalOrder = $victimSection->order;

    $this->actingAs($attacker)
        ->postJson("/api/boards/{$attackerBoard->id}/sections/reorder", [
            'section_ids' => [$victimSection->id, $attackerSection->id],
        ])
        ->assertNoContent();

    expect($victimSection->fresh()->order)->toBe($originalOrder);
});

it('rejects creating a card in a section of another board', function () {
    extract(crossTenantSetup());

    $this->actingAs($attacker)
        ->postJson("/api/boards/{$attackerBoard->id}/cards", [
            'section_id' => $victimSection->id,
            'name' => 'Injected card',
            'description' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['section_id']);
});

it('rejects moving a card into a section of another board via update', function () {
    extract(crossTenantSetup());
    $attackerCard = Card::create(['board_id' => $attackerBoard->id, 'section_id' => $attackerSection->id, 'name' => 'Mine', 'description' => '']);

    $this->actingAs($attacker)
        ->putJson("/api/boards/{$attackerBoard->id}/cards/{$attackerCard->id}", ['section_id' => $victimSection->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['section_id']);
});

it('rejects adding a checklist item to a card of another board', function () {
    extract(crossTenantSetup());

    $this->actingAs($attacker)
        ->postJson("/api/boards/{$attackerBoard->id}/cards/{$victimCard->id}/checklist", ['text' => 'sneaky'])
        ->assertNotFound();

    expect(CardChecklistItem::where('card_id', $victimCard->id)->count())->toBe(0);
});

it('rejects reading and writing comments on a card of another board', function () {
    extract(crossTenantSetup());
    CardComment::create(['card_id' => $victimCard->id, 'user_id' => $victim->id, 'body' => 'internal note']);

    $this->actingAs($attacker)
        ->getJson("/api/boards/{$attackerBoard->id}/cards/{$victimCard->id}/comments")
        ->assertNotFound();

    $this->actingAs($attacker)
        ->postJson("/api/boards/{$attackerBoard->id}/cards/{$victimCard->id}/comments", ['body' => 'hello'])
        ->assertNotFound();

    expect(CardComment::where('card_id', $victimCard->id)->count())->toBe(1);
});

it('lets a user edit their own comment', function () {
    extract(crossTenantSetup());
    $comment = CardComment::create(['card_id' => $victimCard->id, 'user_id' => $victim->id, 'body' => 'original']);

    $this->actingAs($victim)
        ->putJson("/api/boards/{$victimBoard->id}/cards/{$victimCard->id}/comments/{$comment->id}", ['body' => 'edited'])
        ->assertOk()
        ->assertJsonPath('body', 'edited');

    expect($comment->fresh()->body)->toBe('edited');
});

it('rejects editing a comment authored by someone else', function () {
    extract(crossTenantSetup());
    // Attacker is shared onto the victim's board but is not the comment author.
    $victimBoard->sharedWith()->attach($attacker->id, ['permission' => 'write']);
    $comment = CardComment::create(['card_id' => $victimCard->id, 'user_id' => $victim->id, 'body' => 'original']);

    $this->actingAs($attacker)
        ->putJson("/api/boards/{$victimBoard->id}/cards/{$victimCard->id}/comments/{$comment->id}", ['body' => 'hacked'])
        ->assertNotFound();

    expect($comment->fresh()->body)->toBe('original');
});

it('rejects creating a subtask under a card of another board', function () {
    extract(crossTenantSetup());

    $this->actingAs($attacker)
        ->postJson("/api/boards/{$attackerBoard->id}/cards/{$victimCard->id}/subtasks", ['name' => 'sneaky subtask'])
        ->assertNotFound();

    expect(Card::where('parent_card_id', $victimCard->id)->count())->toBe(0);
});

it('rejects deleting a tag that belongs to another board', function () {
    extract(crossTenantSetup());
    $victimTag = Tag::create(['board_id' => $victimBoard->id, 'name' => 'urgent', 'color' => '#ff0000']);

    $this->actingAs($attacker)
        ->deleteJson("/api/boards/{$attackerBoard->id}/tags/{$victimTag->id}")
        ->assertNotFound();

    expect(Tag::find($victimTag->id))->not->toBeNull();
});

it('rejects attaching a tag from another board to a card', function () {
    extract(crossTenantSetup());
    $victimTag = Tag::create(['board_id' => $victimBoard->id, 'name' => 'urgent', 'color' => '#ff0000']);

    $this->actingAs($attacker)
        ->postJson("/api/boards/{$attackerBoard->id}/cards", [
            'section_id' => $attackerSection->id,
            'name' => 'Card',
            'description' => '',
            'tag_ids' => [$victimTag->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tag_ids.0']);
});

it('rejects creating a board inside a project the user does not belong to', function () {
    $attacker = User::factory()->create();
    $victim = User::factory()->create();
    $project = Project::create(['owner_id' => $victim->id, 'name' => 'Victim project']);

    $this->actingAs($attacker)
        ->postJson('/api/boards', ['name' => 'Trojan board', 'description' => '', 'project_id' => $project->id])
        ->assertForbidden();

    expect(Board::where('project_id', $project->id)->count())->toBe(0);
});

it('rejects moving a board into a project the user does not belong to', function () {
    $attacker = User::factory()->create();
    $board = Board::create(['user_id' => $attacker->id, 'name' => 'My board', 'description' => '']);
    $victim = User::factory()->create();
    $project = Project::create(['owner_id' => $victim->id, 'name' => 'Victim project']);

    $this->actingAs($attacker)
        ->putJson("/api/boards/{$board->id}", ['project_id' => $project->id])
        ->assertForbidden();

    expect($board->fresh()->project_id)->toBeNull();
});

it('allows a project member to create a board in that project', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::create(['owner_id' => $owner->id, 'name' => 'Shared project']);
    $project->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member)
        ->postJson('/api/boards', ['name' => 'Team board', 'description' => '', 'project_id' => $project->id])
        ->assertCreated();

    expect(Board::where('project_id', $project->id)->count())->toBe(1);
});

// --- Board deletion is owner-only -------------------------------------------

it('rejects deleting a board you only have a read/write share on', function () {
    $owner = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Keep me', 'description' => '']);
    $reader = User::factory()->create();
    $writer = User::factory()->create();
    BoardShare::create(['board_id' => $board->id, 'user_id' => $reader->id, 'permission' => 'read']);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $writer->id, 'permission' => 'write']);

    $this->actingAs($reader)->deleteJson("/api/boards/{$board->id}")->assertForbidden();
    $this->actingAs($writer)->deleteJson("/api/boards/{$board->id}")->assertForbidden();

    expect(Board::whereKey($board->id)->exists())->toBeTrue();
});

it('rejects deleting a board you have no access to', function () {
    $owner = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Private', 'description' => '']);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->deleteJson("/api/boards/{$board->id}")->assertForbidden();
    expect(Board::whereKey($board->id)->exists())->toBeTrue();
});

it('lets the board creator, an owner-sharer, and the project owner delete', function () {
    // creator
    $creator = User::factory()->create();
    $b1 = Board::create(['user_id' => $creator->id, 'name' => 'A', 'description' => '']);
    $this->actingAs($creator)->deleteJson("/api/boards/{$b1->id}")->assertNoContent();

    // 'owner' share
    $owner = User::factory()->create();
    $coOwner = User::factory()->create();
    $b2 = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '']);
    BoardShare::create(['board_id' => $b2->id, 'user_id' => $coOwner->id, 'permission' => 'owner']);
    $this->actingAs($coOwner)->deleteJson("/api/boards/{$b2->id}")->assertNoContent();

    // project owner deleting a friend's board inside their project
    $projOwner = User::factory()->create();
    $project = Project::create(['owner_id' => $projOwner->id, 'name' => 'P', 'description' => '']);
    $project->members()->attach($projOwner->id, ['role' => 'owner']);
    $friend = User::factory()->create();
    $b3 = Board::create(['user_id' => $friend->id, 'project_id' => $project->id, 'name' => 'C', 'description' => '']);
    $this->actingAs($projOwner)->deleteJson("/api/boards/{$b3->id}")->assertNoContent();

    expect(Board::whereIn('id', [$b1->id, $b2->id, $b3->id])->count())->toBe(0);
});
