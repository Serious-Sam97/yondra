<?php

use App\Events\UserNotificationEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use Illuminate\Support\Facades\Event;

function seedBoard(): array
{
    $owner = User::factory()->create(['name' => 'Owner Person']);
    $member = User::factory()->create(['name' => 'Jane Doe']);
    $board = Board::create(['user_id' => $owner->id, 'name' => 'Board', 'description' => '']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Task', 'description' => '']);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $member->id, 'permission' => 'write']);

    return [$owner, $member, $board, $section, $card];
}

it('notifies a user when they are assigned to a card', function () {
    Event::fake(UserNotificationEvent::class);
    [$owner, $member, $board, , $card] = seedBoard();

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['assigned_user_id' => $member->id])
        ->assertOk();

    $member->refresh();
    expect($member->notifications->contains(fn ($n) => ($n->data['type'] ?? null) === 'card.assigned'))->toBeTrue();

    // And it was broadcast live to the recipient.
    Event::assertDispatched(UserNotificationEvent::class,
        fn ($e) => $e->userId === $member->id && $e->payload['type'] === 'card.assigned');
});

it('does not notify when a user assigns a card to themselves', function () {
    [$owner, , $board, , $card] = seedBoard();

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['assigned_user_id' => $owner->id])
        ->assertOk();

    $owner->refresh();
    expect($owner->notifications()->count())->toBe(0);
});

it('notifies the assignee when someone comments on their card', function () {
    [$owner, $member, $board, , $card] = seedBoard();
    $card->update(['assigned_user_id' => $member->id]);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/comments", ['body' => 'Looks good'])
        ->assertCreated();

    $member->refresh();
    expect($member->notifications->contains(fn ($n) => ($n->data['type'] ?? null) === 'card.commented'))->toBeTrue();
});

it('respects a disabled in-app preference — no persistence, no broadcast', function () {
    Event::fake(UserNotificationEvent::class);
    [$owner, $member, $board, , $card] = seedBoard();

    // Member opts out of in-app for assignments.
    $member->notification_preferences = ['assignment' => ['in_app' => false]];
    $member->save();

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['assigned_user_id' => $member->id])
        ->assertOk();

    $member->refresh();
    expect($member->notifications()->count())->toBe(0);
    Event::assertNotDispatched(UserNotificationEvent::class);
});

it('serves the preference catalog with defaults and persists an update', function () {
    [$owner] = seedBoard();

    $catalog = $this->actingAs($owner)->getJson('/api/notifications/preferences')->assertOk()->json();
    expect($catalog['preferences']['assignment']['in_app'])->toBeTrue();
    expect($catalog['preferences']['comment']['email'])->toBeFalse();
    expect($catalog['event_types'])->not->toBeEmpty();

    $this->actingAs($owner)
        ->putJson('/api/notifications/preferences', ['preferences' => [
            'comment' => ['email' => true],
            'bogus' => ['in_app' => true],   // dropped by sanitize
        ]])
        ->assertOk()
        ->assertJsonPath('preferences.comment.email', true);

    $owner->refresh();
    expect($owner->notification_preferences)->toHaveKey('comment');
    expect($owner->notification_preferences)->not->toHaveKey('bogus');
});

it('exposes typed, deep-linkable notifications over the API and marks them read', function () {
    [$owner, $member, $board, , $card] = seedBoard();

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['assigned_user_id' => $member->id])
        ->assertOk();

    $list = $this->actingAs($member)->getJson('/api/notifications')->assertOk()->json();
    expect($list)->toHaveCount(1);
    expect($list[0]['type'])->toBe('card.assigned');
    expect($list[0]['deep_link'])->toBe("/boards/{$board->id}?card={$card->id}");
    expect($list[0]['read_at'])->toBeNull();

    $this->actingAs($member)->putJson("/api/notifications/{$list[0]['id']}/read")->assertNoContent();

    $member->refresh();
    expect($member->unreadNotifications()->count())->toBe(0);
});
