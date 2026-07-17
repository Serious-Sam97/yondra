<?php

use App\Infrastructure\Models\ErrorGroup;
use App\Infrastructure\Models\ErrorOccurrence;
use App\Infrastructure\Models\User;
use App\Services\Monitoring\ErrorRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Vortex Anomalies error monitor (YON-74). The recorder is disabled under phpunit
 * (ERROR_MONITOR_ENABLED=false) so unrelated tests don't record their expected
 * exceptions — these tests flip it on explicitly.
 */
beforeEach(function () {
    config(['monitoring.enabled' => true]);
});

function errAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

/** Throw site stays constant so repeated calls share a fingerprint. */
function makeError(string $message): Throwable
{
    return new RuntimeException($message);
}

function recorder(): ErrorRecorder
{
    return app(ErrorRecorder::class);
}

// ── Capture / grouping ──────────────────────────────────────────────────────

it('records a throwable as one group with one occurrence', function () {
    recorder()->record(makeError('kaboom'));

    expect(ErrorGroup::count())->toBe(1);
    $group = ErrorGroup::first();
    expect($group->occurrences_count)->toBe(1)
        ->and($group->status)->toBe('open')
        ->and($group->exception_class)->toBe(RuntimeException::class)
        ->and($group->message)->toBe('kaboom')
        ->and($group->source)->toBe('backend');
    expect(ErrorOccurrence::count())->toBe(1);
});

it('folds the same error into one group and bumps the count', function () {
    recorder()->record(makeError('kaboom'));
    recorder()->record(makeError('kaboom'));
    recorder()->record(makeError('kaboom'));

    expect(ErrorGroup::count())->toBe(1);
    expect(ErrorGroup::first()->occurrences_count)->toBe(3);
    expect(ErrorOccurrence::count())->toBe(3);
});

it('groups messages that differ only by volatile ids', function () {
    recorder()->record(makeError('User 42 not found'));
    recorder()->record(makeError('User 9001 not found'));

    expect(ErrorGroup::count())->toBe(1);
    expect(ErrorGroup::first()->occurrences_count)->toBe(2);
});

it('keeps distinct faults in separate groups', function () {
    recorder()->record(makeError('kaboom'));
    recorder()->record(makeError('totally different fault'));

    expect(ErrorGroup::count())->toBe(2);
});

it('skips ignored (expected control-flow) exceptions', function () {
    recorder()->record(new ModelNotFoundException);
    recorder()->record(new NotFoundHttpException('missing'));

    expect(ErrorGroup::count())->toBe(0);
});

it('does nothing when disabled', function () {
    config(['monitoring.enabled' => false]);

    recorder()->record(makeError('kaboom'));

    expect(ErrorGroup::count())->toBe(0);
});

it('caps retained occurrences per group', function () {
    config(['monitoring.occurrence_cap' => 3]);

    foreach (range(1, 6) as $i) {
        recorder()->record(makeError('hot loop'));
    }

    $group = ErrorGroup::first();
    expect($group->occurrences_count)->toBe(6) // the running total is preserved
        ->and($group->occurrences()->count())->toBe(3); // but only 3 rows kept
});

it('auto-reopens a resolved group that recurs', function () {
    recorder()->record(makeError('kaboom'));
    $group = ErrorGroup::first();
    $group->update(['status' => 'resolved', 'resolved_at' => now()]);

    recorder()->record(makeError('kaboom'));

    $group->refresh();
    expect($group->status)->toBe('open')
        ->and($group->resolved_at)->toBeNull();
});

it('leaves an ignored group muted when it recurs', function () {
    recorder()->record(makeError('kaboom'));
    ErrorGroup::first()->update(['status' => 'ignored']);

    recorder()->record(makeError('kaboom'));

    expect(ErrorGroup::first()->status)->toBe('ignored');
});

it('records a client error under the frontend source', function () {
    recorder()->recordClient([
        'name' => 'TypeError',
        'message' => "Cannot read properties of undefined (reading 'x')",
        'stack' => "TypeError: ...\n  at Foo (app.js:1:2)",
        'url' => 'https://app.test/boards/1',
    ]);

    $group = ErrorGroup::first();
    expect($group->source)->toBe('frontend')
        ->and($group->exception_class)->toBe('TypeError');
});

it('ignores an empty client payload', function () {
    recorder()->recordClient([]);

    expect(ErrorGroup::count())->toBe(0);
});

// ── Vortex admin API ────────────────────────────────────────────────────────

it('gates the errors api behind vortex admin', function () {
    $this->actingAs(User::factory()->create()) // non-admin
        ->getJson('/api/vortex/errors')
        ->assertForbidden();
});

it('lists and filters groups by status', function () {
    recorder()->record(makeError('open one'));
    recorder()->record(makeError('resolved one'));
    ErrorGroup::where('message', 'resolved one')->update(['status' => 'resolved']);

    $admin = errAdmin();

    $open = $this->actingAs($admin)->getJson('/api/vortex/errors?status=open')->assertOk();
    expect($open->json('total'))->toBe(1);
    expect($open->json('data.0.message'))->toBe('open one');

    $resolved = $this->actingAs($admin)->getJson('/api/vortex/errors?status=resolved')->assertOk();
    expect($resolved->json('total'))->toBe(1);

    $all = $this->actingAs($admin)->getJson('/api/vortex/errors?status=all')->assertOk();
    expect($all->json('total'))->toBe(2);
});

it('returns headline stats', function () {
    recorder()->record(makeError('a'));
    recorder()->record(makeError('a'));
    recorder()->record(makeError('b'));

    $this->actingAs(errAdmin())
        ->getJson('/api/vortex/errors/stats')
        ->assertOk()
        ->assertJsonStructure(['open', 'resolved', 'ignored', 'total', 'last_24h'])
        ->assertJsonPath('open', 2)
        ->assertJsonPath('total', 2)
        ->assertJsonPath('last_24h', 3);
});

it('shows a group with its occurrences', function () {
    recorder()->record(makeError('detail me'));
    $id = ErrorGroup::first()->id;

    $this->actingAs(errAdmin())
        ->getJson("/api/vortex/errors/{$id}")
        ->assertOk()
        ->assertJsonStructure(['group' => ['id', 'fingerprint', 'status'], 'occurrences']);
});

it('transitions a group through resolve / ignore / reopen', function () {
    recorder()->record(makeError('triage me'));
    $id = ErrorGroup::first()->id;
    $admin = errAdmin();

    $this->actingAs($admin)->postJson("/api/vortex/errors/{$id}/resolve")
        ->assertOk()->assertJsonPath('group.status', 'resolved');
    expect(ErrorGroup::find($id)->resolved_at)->not->toBeNull();

    $this->actingAs($admin)->postJson("/api/vortex/errors/{$id}/ignore")
        ->assertOk()->assertJsonPath('group.status', 'ignored');

    $this->actingAs($admin)->postJson("/api/vortex/errors/{$id}/reopen")
        ->assertOk()->assertJsonPath('group.status', 'open');
    expect(ErrorGroup::find($id)->resolved_at)->toBeNull();
});

it('deletes a group and cascades its occurrences', function () {
    recorder()->record(makeError('delete me'));
    $id = ErrorGroup::first()->id;

    $this->actingAs(errAdmin())
        ->deleteJson("/api/vortex/errors/{$id}")
        ->assertNoContent();

    expect(ErrorGroup::count())->toBe(0);
    expect(ErrorOccurrence::count())->toBe(0);
});

// ── Public frontend ingest webhook ──────────────────────────────────────────

it('ingests a browser error with a valid token', function () {
    config(['monitoring.ingest_token' => 'secret-token']);

    $this->postJson('/api/webhooks/errors/secret-token', [
        'name' => 'TypeError',
        'message' => 'boom in the browser',
        'url' => 'https://app.test/x',
    ])->assertCreated();

    $group = ErrorGroup::first();
    expect($group)->not->toBeNull()
        ->and($group->source)->toBe('frontend');
});

it('rejects ingest with a bad token', function () {
    config(['monitoring.ingest_token' => 'secret-token']);

    $this->postJson('/api/webhooks/errors/wrong-token', [
        'message' => 'boom',
    ])->assertNotFound();

    expect(ErrorGroup::count())->toBe(0);
});

it('rejects ingest when no token is configured', function () {
    config(['monitoring.ingest_token' => null]);

    $this->postJson('/api/webhooks/errors/anything', ['message' => 'boom'])
        ->assertNotFound();
});
