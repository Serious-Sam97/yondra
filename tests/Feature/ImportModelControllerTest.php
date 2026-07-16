<?php

use App\Infrastructure\Models\ImportModel;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;

/** An owner + their project; returns [$owner, $project]. */
function ownedProject(): array
{
    $owner = User::factory()->create();
    $project = Project::create(['owner_id' => $owner->id, 'name' => 'Acme', 'description' => '']);

    return [$owner, $project];
}

function sampleModelPayload(): array
{
    return [
        'name' => 'Zendesk Export',
        'mode' => 'many',
        'item_path' => 'results',
        'fields' => [
            ['target' => 'name', 'source' => 'subject'],
            ['target' => 'tags', 'source' => 'labels', 'transform' => ['type' => 'split', 'delimiter' => ',']],
            ['target' => 'column', 'transform' => ['type' => 'const', 'value' => 'Triage']],
        ],
        'sample' => ['results' => [['subject' => 'x', 'labels' => 'a,b']]],
    ];
}

it('lets a project owner create a model', function () {
    [$owner, $project] = ownedProject();

    $res = $this->actingAs($owner)
        ->postJson("/api/projects/{$project->id}/import-models", sampleModelPayload())
        ->assertCreated()
        ->assertJson(['name' => 'Zendesk Export', 'mode' => 'many', 'item_path' => 'results']);

    $model = ImportModel::findOrFail($res->json('id'));
    expect($model->project_id)->toBe($project->id);
    expect($model->created_by)->toBe($owner->id);
    expect($model->fields)->toHaveCount(3);
    expect($model->sample)->toBeArray();
});

it('lists a project’s models for a member', function () {
    [$owner, $project] = ownedProject();
    ImportModel::create(['project_id' => $project->id, 'name' => 'B', 'mode' => 'many', 'fields' => []]);
    ImportModel::create(['project_id' => $project->id, 'name' => 'A', 'mode' => 'many', 'fields' => []]);

    $this->actingAs($owner)
        ->getJson("/api/projects/{$project->id}/import-models")
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.name', 'A'); // ordered by name
});

it('updates a model, leaving unsent fields untouched', function () {
    [$owner, $project] = ownedProject();
    $model = ImportModel::create([
        'project_id' => $project->id, 'name' => 'Old', 'mode' => 'many',
        'fields' => [['target' => 'name', 'source' => 'subject']],
    ]);

    $this->actingAs($owner)
        ->putJson("/api/projects/{$project->id}/import-models/{$model->id}", ['name' => 'New'])
        ->assertOk()
        ->assertJson(['name' => 'New']);

    $model->refresh();
    expect($model->name)->toBe('New');
    expect($model->fields)->toHaveCount(1); // mapping preserved
});

it('deletes a model', function () {
    [$owner, $project] = ownedProject();
    $model = ImportModel::create(['project_id' => $project->id, 'name' => 'X', 'mode' => 'many', 'fields' => []]);

    $this->actingAs($owner)
        ->deleteJson("/api/projects/{$project->id}/import-models/{$model->id}")
        ->assertNoContent();

    expect(ImportModel::find($model->id))->toBeNull();
});

it('preserves transform params (scale map, const value) through save', function () {
    [$owner, $project] = ownedProject();

    $res = $this->actingAs($owner)
        ->postJson("/api/projects/{$project->id}/import-models", [
            'name' => 'Transforms',
            'fields' => [
                ['target' => 'priority', 'source' => 'sev', 'transform' => [
                    'type' => 'scale', 'map' => ['3' => 'high', '4' => 'high'],
                ]],
                ['target' => 'column', 'transform' => ['type' => 'const', 'value' => 'Triage']],
            ],
        ])
        ->assertCreated();

    // Regression: validated() drops un-ruled nested keys; the controller must keep
    // the raw field objects so map/value survive (else preview/import breaks).
    $model = ImportModel::findOrFail($res->json('id'));
    expect($model->fields[0]['transform']['map'])->toBe(['3' => 'high', '4' => 'high']);
    expect($model->fields[1]['transform']['value'])->toBe('Triage');
});

it('rejects an unknown field target', function () {
    [$owner, $project] = ownedProject();

    $this->actingAs($owner)
        ->postJson("/api/projects/{$project->id}/import-models", [
            'name' => 'Bad',
            'fields' => [['target' => 'not_a_field', 'source' => 'x']],
        ])
        ->assertStatus(422);
});

it('forbids a non-member from listing or creating models', function () {
    [, $project] = ownedProject();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->getJson("/api/projects/{$project->id}/import-models")
        ->assertForbidden();

    $this->actingAs($outsider)
        ->postJson("/api/projects/{$project->id}/import-models", sampleModelPayload())
        ->assertForbidden();

    expect(ImportModel::count())->toBe(0);
});
