<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\TestCase as QaCase;
use App\Infrastructure\Models\TestRun;
use App\Infrastructure\Models\User;

function qaCard(User $owner, bool $qa = true): array
{
    $board = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '', 'type' => 'kanban', 'qa_enabled' => $qa]);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Feature', 'description' => '']);

    return [$board, $card];
}

it('creates and lists multiple test cases on one card', function () {
    $owner = User::factory()->create();
    [$board, $card] = qaCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/qa";

    $this->actingAs($owner)->postJson("{$base}/cases", ['title' => 'Login válido', 'type' => 'manual'])->assertCreated();
    $this->actingAs($owner)->postJson("{$base}/cases", ['title' => 'Login inválido'])->assertCreated();

    $res = $this->actingAs($owner)->getJson($base)->assertOk();
    expect($res->json('cases'))->toHaveCount(2)
        ->and($res->json('cases.0.title'))->toBe('Login válido')
        ->and($res->json('cases.0.latest_status'))->toBe('not_run');
});

it('bumps the version and records the editor on update', function () {
    $owner = User::factory()->create();
    [$board, $card] = qaCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/qa";

    $id = $this->actingAs($owner)->postJson("{$base}/cases", ['title' => 'Case'])->json('id');
    expect(QaCase::find($id)->version)->toBe(1);

    $res = $this->actingAs($owner)->putJson("{$base}/cases/{$id}", ['gherkin' => 'Dado ...'])->assertOk();
    expect($res->json('version'))->toBe(2)
        ->and(QaCase::find($id)->edited_by_user_id)->toBe($owner->id);
});

it('appends runs and reflects the newest as latest_status', function () {
    $owner = User::factory()->create();
    [$board, $card] = qaCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/qa";
    $id = $this->actingAs($owner)->postJson("{$base}/cases", ['title' => 'Case'])->json('id');

    $this->actingAs($owner)->postJson("{$base}/cases/{$id}/runs", ['status' => 'failed'])->assertCreated();
    $res = $this->actingAs($owner)->postJson("{$base}/cases/{$id}/runs", ['status' => 'passed'])->assertCreated();

    // Both runs are kept (append-only); latest_status is the newest.
    expect($res->json('runs'))->toHaveCount(2)
        ->and($res->json('latest_status'))->toBe('passed');
});

it('is blocked when QA is disabled on the board', function () {
    $owner = User::factory()->create();
    [$board, $card] = qaCard($owner, qa: false);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/qa/cases", ['title' => 'X'])
        ->assertStatus(422);
});

it('manages a reusable step library and references it from a case', function () {
    $owner = User::factory()->create();
    [$board, $card] = qaCard($owner);
    $qaBase = "/api/boards/{$board->id}/cards/{$card->id}/qa";
    $stepBase = "/api/boards/{$board->id}/qa/steps";

    $step = $this->actingAs($owner)->postJson($stepBase, ['title' => 'Realizar login', 'content' => 'Abrir /login'])->assertCreated()->json();
    expect($this->actingAs($owner)->getJson($stepBase)->json('steps'))->toHaveCount(1);

    // A case references the step by id.
    $caseId = $this->actingAs($owner)->postJson("{$qaBase}/cases", ['title' => 'C'])->json('id');
    $res = $this->actingAs($owner)->putJson("{$qaBase}/cases/{$caseId}", ['step_refs' => [['step_id' => $step['id']]]])->assertOk();
    expect($res->json('step_refs.0.step_id'))->toBe($step['id']);

    // Editing the library entry updates it (propagation resolves client-side by id).
    $upd = $this->actingAs($owner)->putJson("{$stepBase}/{$step['id']}", ['content' => 'novo conteúdo'])->assertOk();
    expect($upd->json('content'))->toBe('novo conteúdo');
});

it('blocks step creation when QA is disabled', function () {
    $owner = User::factory()->create();
    [$board] = qaCard($owner, qa: false);
    $this->actingAs($owner)->postJson("/api/boards/{$board->id}/qa/steps", ['title' => 'X'])->assertStatus(422);
});

it('quality gate blocks a move to Done with failing or not-run tests', function () {
    $owner = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '', 'type' => 'kanban', 'qa_enabled' => true]);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $done = Section::create(['board_id' => $board->id, 'name' => 'Done']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Feature', 'description' => '']);
    $case = QaCase::create(['board_id' => $board->id, 'card_id' => $card->id, 'title' => 'T']);
    TestRun::create(['test_case_id' => $case->id, 'board_id' => $board->id, 'status' => 'failed', 'executed_at' => now()]);

    $reorder = "/api/boards/{$board->id}/cards/reorder";

    // Failing test → blocked, card stays put.
    $this->actingAs($owner)->putJson($reorder, ['section_id' => $done->id, 'ordered_ids' => [$card->id]])->assertStatus(422);
    expect(Card::find($card->id)->section_id)->toBe($todo->id);

    // A newer passing run opens the gate.
    TestRun::create(['test_case_id' => $case->id, 'board_id' => $board->id, 'status' => 'passed', 'executed_at' => now()->addMinute()]);
    $this->actingAs($owner)->putJson($reorder, ['section_id' => $done->id, 'ordered_ids' => [$card->id]])->assertOk();
    expect(Card::find($card->id)->section_id)->toBe($done->id);
});

it('quality gate also blocks the card-update path into Done, not just reorder', function () {
    $owner = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '', 'type' => 'kanban', 'qa_enabled' => true]);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $done = Section::create(['board_id' => $board->id, 'name' => 'Done']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Feature', 'description' => '']);
    $case = QaCase::create(['board_id' => $board->id, 'card_id' => $card->id, 'title' => 'T']);
    TestRun::create(['test_case_id' => $case->id, 'board_id' => $board->id, 'status' => 'failed', 'executed_at' => now()]);

    $update = "/api/boards/{$board->id}/cards/{$card->id}";

    // Failing test → a plain update with section_id may not sneak into Done.
    $this->actingAs($owner)->putJson($update, ['section_id' => $done->id])->assertStatus(422);
    $fresh = Card::find($card->id);
    expect($fresh->section_id)->toBe($todo->id)
        ->and($fresh->done_at)->toBeNull();

    // A newer passing run opens the gate on this path too.
    TestRun::create(['test_case_id' => $case->id, 'board_id' => $board->id, 'status' => 'passed', 'executed_at' => now()->addMinute()]);
    $this->actingAs($owner)->putJson($update, ['section_id' => $done->id])->assertOk();
    expect(Card::find($card->id)->section_id)->toBe($done->id);
});

it('couples a bug: resolving it flips the case to awaiting-retest, a new run clears it', function () {
    $owner = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '', 'type' => 'kanban', 'qa_enabled' => true]);
    $todo = Section::create(['board_id' => $board->id, 'name' => 'To Do', 'order' => 0]);
    $done = Section::create(['board_id' => $board->id, 'name' => 'Done', 'order' => 1]);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $todo->id, 'name' => 'Feature', 'description' => '']);
    $case = QaCase::create(['board_id' => $board->id, 'card_id' => $card->id, 'title' => 'T']);
    TestRun::create(['test_case_id' => $case->id, 'board_id' => $board->id, 'status' => 'failed', 'executed_at' => now()]);
    $qaBase = "/api/boards/{$board->id}/cards/{$card->id}/qa";

    // A failure spawns a bug card, coupled to the case.
    $linked = $this->actingAs($owner)->postJson("{$qaBase}/cases/{$case->id}/bug")->assertOk()->json();
    $bugCardId = $linked['bug_card_id'];
    expect($bugCardId)->not->toBeNull();

    // Resolving the bug (moving it to Done) flips the case to awaiting-retest.
    $this->actingAs($owner)->putJson("/api/boards/{$board->id}/cards/reorder", ['section_id' => $done->id, 'ordered_ids' => [$bugCardId]])->assertOk();
    expect(QaCase::find($case->id)->awaiting_retest)->toBeTrue();

    // A new run IS the retest — clears the flag.
    $res = $this->actingAs($owner)->postJson("{$qaBase}/cases/{$case->id}/runs", ['status' => 'passed'])->assertCreated();
    expect($res->json('awaiting_retest'))->toBeFalse()
        ->and($res->json('latest_status'))->toBe('passed');
});

it('sets a human verdict independent of run status, and clears it', function () {
    $owner = User::factory()->create();
    [$board, $card] = qaCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/qa";
    $id = $this->actingAs($owner)->postJson("{$base}/cases", ['title' => 'C'])->json('id');

    // A passing run, yet a human rejects it — the verdict wins.
    $this->actingAs($owner)->postJson("{$base}/cases/{$id}/runs", ['status' => 'passed'])->assertCreated();
    $res = $this->actingAs($owner)->postJson("{$base}/cases/{$id}/verdict", ['verdict' => 'rejected'])->assertOk();
    expect($res->json('verdict'))->toBe('rejected')
        ->and($res->json('verdict_by.id'))->toBe($owner->id)
        ->and($res->json('latest_status'))->toBe('passed');

    // Clearing the verdict.
    $cleared = $this->actingAs($owner)->postJson("{$base}/cases/{$id}/verdict", ['verdict' => null])->assertOk();
    expect($cleared->json('verdict'))->toBeNull()
        ->and($cleared->json('verdict_by'))->toBeNull();

    // Bogus verdicts are rejected.
    $this->actingAs($owner)->postJson("{$base}/cases/{$id}/verdict", ['verdict' => 'lgtm'])->assertStatus(422);
});

it('stores a per-block checklist layer on an append-only run', function () {
    $owner = User::factory()->create();
    [$board, $card] = qaCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/qa";
    $id = $this->actingAs($owner)->postJson("{$base}/cases", ['title' => 'C'])->json('id');

    $items = [
        ['block_key' => 'g1', 'block_title' => 'Login', 'ok' => true],
        ['block_key' => 'l_a', 'block_title' => 'Cupom', 'ok' => false, 'bug_card_id' => 105, 'evidence' => [['url' => 'x.png', 'kind' => 'image']]],
    ];
    $res = $this->actingAs($owner)->postJson("{$base}/cases/{$id}/runs", ['status' => 'failed', 'items' => $items])->assertCreated();

    expect($res->json('runs.0.status'))->toBe('failed')
        ->and($res->json('runs.0.source'))->toBe('manual')
        ->and($res->json('runs.0.items'))->toHaveCount(2)
        ->and($res->json('runs.0.items.1.ok'))->toBeFalse()
        ->and($res->json('runs.0.items.1.bug_card_id'))->toBe(105);
});

it('accepts CI results via the public webhook token and rejects a bad token', function () {
    $owner = User::factory()->create();
    [$board, $card] = qaCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/qa";
    $id = $this->actingAs($owner)->postJson("{$base}/cases", ['title' => 'C'])->json('id');

    $token = $this->actingAs($owner)->postJson("{$base}/cases/{$id}/ci-token")->assertOk()->json('ci_token');
    expect($token)->not->toBeNull();

    // Public — no auth. The unguessable token IS the credential.
    $this->postJson("/api/webhooks/qa-ci/{$token}", ['status' => 'passed', 'logs' => 'ci green'])
        ->assertCreated()->assertJson(['ok' => true]);

    $run = TestRun::where('test_case_id', $id)->latest('id')->first();
    expect($run->status)->toBe('passed')->and($run->source)->toBe('ci');

    $this->postJson('/api/webhooks/qa-ci/not-a-real-token', ['status' => 'passed'])->assertNotFound();
});

it('persists local blocks and per-block evidence inside step_refs', function () {
    $owner = User::factory()->create();
    [$board, $card] = qaCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/qa";
    $id = $this->actingAs($owner)->postJson("{$base}/cases", ['title' => 'C'])->json('id');

    $refs = [
        ['local_key' => 'l_1', 'scope' => 'local', 'title' => 'Validar cupom', 'lines' => [
            ['keyword' => 'DADO', 'text' => 'no checkout'],
            ['keyword' => 'ENTÃO', 'text' => 'QR exibido'],
        ], 'evidence' => [['url' => 'doc.png', 'kind' => 'image']]],
    ];
    $res = $this->actingAs($owner)->putJson("{$base}/cases/{$id}", ['step_refs' => $refs])->assertOk();

    expect($res->json('step_refs.0.title'))->toBe('Validar cupom')
        ->and($res->json('step_refs.0.lines'))->toHaveCount(2)
        ->and($res->json('step_refs.0.evidence.0.url'))->toBe('doc.png');
});

it('stores structured gherkin lines on a reusable step', function () {
    $owner = User::factory()->create();
    [$board] = qaCard($owner);
    $stepBase = "/api/boards/{$board->id}/qa/steps";

    $res = $this->actingAs($owner)->postJson($stepBase, [
        'title' => 'Login',
        'gherkin_lines' => [['keyword' => 'QUANDO', 'text' => 'faz login']],
    ])->assertCreated();

    expect($res->json('gherkin_lines.0.keyword'))->toBe('QUANDO')
        ->and($res->json('gherkin_lines.0.text'))->toBe('faz login');
});

it('manages test plans and links cases to them', function () {
    $owner = User::factory()->create();
    [$board, $card] = qaCard($owner);
    $planBase = "/api/boards/{$board->id}/qa/plans";
    $qaBase = "/api/boards/{$board->id}/cards/{$card->id}/qa";

    $plan = $this->actingAs($owner)->postJson($planBase, ['name' => 'Regressão v2.0'])->assertCreated()->json();
    expect($this->actingAs($owner)->getJson($planBase)->json('plans'))->toHaveCount(1);

    // Link a case to the plan.
    $caseId = $this->actingAs($owner)->postJson("{$qaBase}/cases", ['title' => 'C'])->json('id');
    $res = $this->actingAs($owner)->putJson("{$qaBase}/cases/{$caseId}", ['test_plan_ids' => [$plan['id']]])->assertOk();
    expect($res->json('test_plan_ids'))->toBe([$plan['id']]);

    // The plan now counts the linked case (cross-card suite).
    $plans = $this->actingAs($owner)->getJson($planBase)->json('plans');
    expect($plans[0]['cases_count'])->toBe(1);
});
