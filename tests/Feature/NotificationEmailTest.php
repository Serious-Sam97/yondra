<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;

function emailSeed(): array
{
    $owner  = User::factory()->create(['name' => 'Owner Person']);
    $member = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
    $board  = Board::create(['user_id' => $owner->id, 'name' => 'Board', 'description' => '']);
    $todo   = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card   = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Task', 'description' => '']);
    BoardShare::create(['board_id' => $board->id, 'user_id' => $member->id, 'permission' => 'write']);

    return [$owner, $member, $board, $todo, $card];
}

it('queues an email for an event whose email channel is on by default (assignment)', function () {
    Mail::fake();
    [$owner, $member, $board, , $card] = emailSeed();

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['assigned_user_id' => $member->id])
        ->assertOk();

    Mail::assertQueued(NotificationMail::class, fn ($mail) => $mail->hasTo('jane@example.com')
        && $mail->eyebrow === 'Assignment');
});

it('does not email for an event whose email channel is off by default (comment)', function () {
    Mail::fake();
    [$owner, $member, $board, , $card] = emailSeed();
    $card->update(['assigned_user_id' => $member->id]);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/comments", ['body' => 'hi'])
        ->assertCreated();

    Mail::assertNotQueued(NotificationMail::class);
});

it('does not email when the recipient has disabled the email channel', function () {
    Mail::fake();
    [$owner, $member, $board, , $card] = emailSeed();
    $member->notification_preferences = ['assignment' => ['email' => false]];
    $member->save();

    $this->actingAs($owner)
        ->putJson("/api/boards/{$board->id}/cards/{$card->id}", ['assigned_user_id' => $member->id])
        ->assertOk();

    Mail::assertNotQueued(NotificationMail::class);
    // …but the in-app bell notification still lands (in_app still on).
    expect($member->fresh()->notifications()->count())->toBe(1);
});
