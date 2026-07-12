<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardLink;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Sprint;
use App\Infrastructure\Models\User;

function seedDashboard(User $user): array
{
    $project = Project::create(['owner_id' => $user->id, 'name' => 'Core', 'description' => '']);
    $board = Board::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => 'Kanban', 'description' => '']);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do', 'order' => 0]);
    $done = Section::create(['board_id' => $board->id, 'name' => 'Done', 'order' => 1]);

    // my open cards: overdue / due today / high-priority upcoming / plain open
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'assigned_user_id' => $user->id, 'name' => 'Overdue task', 'description' => '', 'due_date' => now()->subDay()->toDateString(), 'story_points' => 5]);
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'assigned_user_id' => $user->id, 'name' => 'Today task', 'description' => '', 'due_date' => now()->toDateString()]);
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'assigned_user_id' => $user->id, 'name' => 'Big rock', 'description' => '', 'due_date' => now()->addWeek()->toDateString(), 'priority' => 'high']);
    Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'assigned_user_id' => $user->id, 'name' => 'Plain open', 'description' => '']);
    // completed today -> feeds done_7d + throughput
    Card::create(['board_id' => $board->id, 'section_id' => $done->id, 'assigned_user_id' => $user->id, 'name' => 'Shipped', 'description' => '', 'done_at' => now()]);

    // active sprint
    Sprint::create([
        'board_id' => $board->id, 'name' => 'Sprint 1', 'status' => 'active', 'is_active' => true,
        'start_date' => now()->subDays(5)->toDateString(), 'end_date' => now()->addDays(5)->toDateString(),
        'committed_points' => 45, 'completed_points' => 28, 'committed_count' => 10, 'completed_count' => 6,
    ]);

    // open pull request
    CardLink::create([
        'card_id' => Card::where('board_id', $board->id)->first()->id, 'board_id' => $board->id,
        'created_by_user_id' => $user->id, 'provider' => 'github', 'type' => 'pr', 'url' => 'https://github.com/x/y/pull/218',
        'number' => 218, 'title' => 'feat: whatsapp driver', 'state' => 'open', 'checks_state' => 'success', 'last_synced_at' => now(),
    ]);

    BoardActivity::create(['board_id' => $board->id, 'user_id' => $user->id, 'type' => 'card.moved', 'description' => 'moved Overdue task']);

    // CRM board with pipeline + aging + won-this-month
    $crm = Board::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => 'Sales', 'description' => '', 'type' => 'crm', 'currency' => 'USD']);
    $lead = Section::create(['board_id' => $crm->id, 'name' => 'Lead', 'order' => 0]);
    $prop = Section::create(['board_id' => $crm->id, 'name' => 'Proposal', 'order' => 1]);
    Card::create(['board_id' => $crm->id, 'section_id' => $prop->id, 'name' => 'Acme Corp', 'description' => '', 'value' => 24000, 'section_entered_at' => now()->subDays(9)]);
    Card::create(['board_id' => $crm->id, 'section_id' => $lead->id, 'name' => 'Globex', 'description' => '', 'value' => 10000, 'section_entered_at' => now()->subDay()]);
    Card::create(['board_id' => $crm->id, 'section_id' => $prop->id, 'name' => 'Won deal', 'description' => '', 'value' => 5000, 'done_at' => now()]);

    return compact('project', 'board', 'crm');
}

it('requires authentication', function () {
    $this->getJson('/api/dashboard')->assertStatus(401);
});

it('returns the aggregate dashboard payload', function () {
    $user = User::factory()->create();
    seedDashboard($user);

    $res = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

    // vitals
    expect($res->json('vitals.overdue'))->toBe(1);
    expect($res->json('vitals.overdue_oldest_days'))->toBe(1);
    expect($res->json('vitals.due_today'))->toBe(1);
    // due this week = Today task + Big rock (due in exactly 7 days)
    expect($res->json('vitals.due_week'))->toBe(2);
    expect($res->json('vitals.next_due'))->toBe(now()->toDateString());
    expect($res->json('vitals.in_progress'))->toBe(4);
    expect($res->json('vitals.in_progress_boards'))->toBe(1);
    expect($res->json('vitals.done_7d'))->toBe(1);
    expect($res->json('vitals.done_prev_7d'))->toBe(0);
    expect((float) $res->json('vitals.pipeline'))->toBe(34000.0);

    // queue groups
    expect($res->json('queue.overdue.0.name'))->toBe('Overdue task');
    expect($res->json('queue.today.0.name'))->toBe('Today task');
    expect($res->json('queue.high.0.name'))->toBe('Big rock');

    // throughput: 14 buckets, at least one completion
    expect($res->json('throughput'))->toHaveCount(14);
    expect(array_sum($res->json('throughput')))->toBeGreaterThanOrEqual(1);

    // active sprint
    expect($res->json('sprint.name'))->toBe('Sprint 1');
    expect($res->json('sprint.board_name'))->toBe('Kanban');
    expect($res->json('sprint.committed'))->toBe(45);
    expect($res->json('sprint.remaining'))->toBe(17);

    // CRM
    expect((float) $res->json('crm.open_total'))->toBe(34000.0);
    expect((float) $res->json('crm.won_mtd'))->toBe(5000.0);
    expect($res->json('crm.open_count'))->toBe(2);
    expect($res->json('crm.top_deal.name'))->toBe('Acme Corp');
    expect($res->json('crm.top_deal.stage'))->toBe('Proposal');
    expect((float) $res->json('crm.top_deal.value'))->toBe(24000.0);
    expect($res->json('crm.stages.0.count'))->toBe(1);
    expect($res->json('crm.aging.0.name'))->toBe('Acme Corp');
    expect($res->json('crm.aging.0.days_idle'))->toBe(9);
    expect($res->json('crm.aging.0.board_id'))->not->toBeNull();

    // PRs + activity + projects
    expect($res->json('prs.0.title'))->toBe('feat: whatsapp driver');
    expect($res->json('activity.0.description'))->toBe('moved Overdue task');
    expect($res->json('projects.owned'))->not->toBeEmpty();

    // per-project live signal: kanban (5 cards, 1 done) + crm (3 cards, 1 done)
    expect($res->json('projects_meta.0.total'))->toBe(8);
    expect($res->json('projects_meta.0.done'))->toBe(2);
    expect($res->json('projects_meta.0.last_activity'))->not->toBeNull();
});

it('strips the actor name from legacy activity descriptions', function () {
    $user = User::factory()->create();
    $board = Board::create(['user_id' => $user->id, 'name' => 'B', 'description' => '']);
    BoardActivity::create([
        'board_id' => $board->id, 'user_id' => $user->id, 'type' => 'card_created',
        'description' => $user->name.' created card "x"',
    ]);

    $res = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

    expect($res->json('activity.0.actor'))->toBe($user->name);
    expect($res->json('activity.0.description'))->toBe('created card "x"');
});

it('scopes data to the authenticated user', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    seedDashboard($me);

    // another user's board + assigned overdue card must never leak in
    $otherBoard = Board::create(['user_id' => $other->id, 'name' => 'Theirs', 'description' => '']);
    $otherSection = Section::create(['board_id' => $otherBoard->id, 'name' => 'To Do', 'order' => 0]);
    Card::create(['board_id' => $otherBoard->id, 'section_id' => $otherSection->id, 'assigned_user_id' => $other->id, 'name' => 'Secret task', 'description' => '', 'due_date' => now()->subDay()->toDateString()]);

    $res = $this->actingAs($me)->getJson('/api/dashboard')->assertOk();

    expect($res->json('vitals.overdue'))->toBe(1);
    $res->assertJsonMissing(['name' => 'Secret task']);
});
