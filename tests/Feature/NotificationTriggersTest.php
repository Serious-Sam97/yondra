<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use Illuminate\Support\Carbon;

function boardWithMember(): array
{
    $owner = User::factory()->create(['name' => 'Owner Person']);
    $member = User::factory()->create(['name' => 'Jane Doe']);
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Board', 'description' => '']);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $done = Section::create(['board_id' => $board->id, 'name' => 'Done']);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $member->id, 'permission' => 'write']);

    return [$owner, $member, $board, $todo, $done];
}

it('notifies the assignee when someone moves their card to another column', function () {
    [$owner, $member, $board, $todo, $done] = boardWithMember();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Task', 'description' => '', 'assigned_user_id' => $member->id]);

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/reorder", ['section_id' => $done->id, 'ordered_ids' => [$card->id]])
        ->assertOk();

    $member->refresh();
    expect($member->notifications->contains(fn ($n) => ($n->data['type'] ?? null) === 'card.status'))->toBeTrue();
});

it('does not notify when the assignee moves their own card', function () {
    [, $member, $board, $todo, $done] = boardWithMember();
    $card = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Task', 'description' => '', 'assigned_user_id' => $member->id]);

    $this->actingAs($member)
        ->putJson("/api/boards/{$board->id}/cards/reorder", ['section_id' => $done->id, 'ordered_ids' => [$card->id]])
        ->assertOk();

    $member->refresh();
    expect($member->notifications()->count())->toBe(0);
});

it('notifies a user when a board is shared with them', function () {
    $owner = User::factory()->create(['name' => 'Owner Person']);
    $invitee = User::factory()->create(['name' => 'New Guy']);
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Shared Board', 'description' => '']);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/share", ['user_id' => $invitee->id, 'permission' => 'write'])
        ->assertCreated();

    $invitee->refresh();
    expect($invitee->notifications->contains(fn ($n) => ($n->data['type'] ?? null) === 'board.shared'))->toBeTrue();
});

it('sends a due-date reminder once per due window', function () {
    [, $member, $board, $todo] = boardWithMember();
    $card = Card::create([
        'board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Due soon', 'description' => '',
        'assigned_user_id' => $member->id, 'due_date' => Carbon::today()->toDateString(),
    ]);

    $this->artisan('notifications:due-reminders')->assertSuccessful();
    $member->refresh();
    expect($member->notifications()->count())->toBe(1);
    expect($card->fresh()->due_reminder_sent_at)->not->toBeNull();

    // Running again does not double-send.
    $this->artisan('notifications:due-reminders')->assertSuccessful();
    expect($member->fresh()->notifications()->count())->toBe(1);
});

it('collapses board chat into one unread ping per board', function () {
    [$owner, $member, $board] = boardWithMember();

    // Two messages from the owner → the member should get exactly one unread chat ping.
    $this->actingAs($owner)->postJson("/api/boards/{$board->id}/messages", ['body' => 'hello team'])->assertCreated();
    $this->actingAs($owner)->postJson("/api/boards/{$board->id}/messages", ['body' => 'anyone there?'])->assertCreated();

    $member->refresh();
    $chat = $member->notifications->filter(fn ($n) => ($n->data['type'] ?? null) === 'board.chat');
    expect($chat)->toHaveCount(1);
});
