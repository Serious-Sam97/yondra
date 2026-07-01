<?php

use App\Infrastructure\Models\Board;
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
    $attacker      = User::factory()->create();
    $attackerBoard = Board::create(['user_id' => $attacker->id, 'name' => 'Attacker board', 'description' => '']);
    $attackerSection = Section::create(['board_id' => $attackerBoard->id, 'name' => 'To Do']);

    $victim        = User::factory()->create();
    $victimBoard   = Board::create(['user_id' => $victim->id, 'name' => 'Victim board', 'description' => '']);
    $victimSection = Section::create(['board_id' => $victimBoard->id, 'name' => 'Private', 'order' => 5]);
    $victimCard    = Card::create(['board_id' => $victimBoard->id, 'section_id' => $victimSection->id, 'name' => 'Secret task', 'description' => '']);

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
            'section_id'  => $victimSection->id,
            'name'        => 'Injected card',
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
            'section_id'  => $attackerSection->id,
            'name'        => 'Card',
            'description' => '',
            'tag_ids'     => [$victimTag->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tag_ids.0']);
});

it('rejects creating a board inside a project the user does not belong to', function () {
    $attacker = User::factory()->create();
    $victim   = User::factory()->create();
    $project  = Project::create(['owner_id' => $victim->id, 'name' => 'Victim project']);

    $this->actingAs($attacker)
        ->postJson('/api/boards', ['name' => 'Trojan board', 'description' => '', 'project_id' => $project->id])
        ->assertForbidden();

    expect(Board::where('project_id', $project->id)->count())->toBe(0);
});

it('rejects moving a board into a project the user does not belong to', function () {
    $attacker = User::factory()->create();
    $board    = Board::create(['user_id' => $attacker->id, 'name' => 'My board', 'description' => '']);
    $victim   = User::factory()->create();
    $project  = Project::create(['owner_id' => $victim->id, 'name' => 'Victim project']);

    $this->actingAs($attacker)
        ->putJson("/api/boards/{$board->id}", ['project_id' => $project->id])
        ->assertForbidden();

    expect($board->fresh()->project_id)->toBeNull();
});

it('allows a project member to create a board in that project', function () {
    $owner   = User::factory()->create();
    $member  = User::factory()->create();
    $project = Project::create(['owner_id' => $owner->id, 'name' => 'Shared project']);
    $project->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member)
        ->postJson('/api/boards', ['name' => 'Team board', 'description' => '', 'project_id' => $project->id])
        ->assertCreated();

    expect(Board::where('project_id', $project->id)->count())->toBe(1);
});
