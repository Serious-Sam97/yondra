<?php

use App\Services\CardImport\ImportModelMapper;

beforeEach(function () {
    $this->mapper = new ImportModelMapper;
});

// A realistic "Zendesk export" style model used across several cases.
function zendeskModel(): array
{
    return [
        'mode' => 'many',
        'item_path' => 'results',
        'fields' => [
            ['target' => 'name', 'source' => 'subject'],
            ['target' => 'description', 'source' => 'description'],
            ['target' => 'priority', 'source' => 'priority', 'transform' => [
                'type' => 'scale',
                'map' => ['1' => 'low', '2' => 'low', '3' => 'high', '4' => 'high'],
            ]],
            ['target' => 'tags', 'source' => 'labels', 'transform' => ['type' => 'split', 'delimiter' => ',']],
            ['target' => 'due_date', 'source' => 'created_at', 'transform' => ['type' => 'date']],
            ['target' => 'column', 'transform' => ['type' => 'const', 'value' => 'Triage']],
            ['target' => 'contact_email', 'source' => 'requester.email'],
        ],
    ];
}

// ─── item location ───────────────────────────────────────────────────────────

it('locates many items at a dot-path', function () {
    $json = ['results' => [['subject' => 'A'], ['subject' => 'B']]];

    [$rows, $err] = $this->mapper->apply(zendeskModel(), $json);

    expect($err)->toBeNull();
    expect($rows)->toHaveCount(2);
    expect($rows[0]['name'])->toBe('A');
    expect($rows[1]['name'])->toBe('B');
});

it('locates items at the root when item_path is empty', function () {
    $model = ['mode' => 'many', 'item_path' => '', 'fields' => [['target' => 'name', 'source' => 'subject']]];

    [$rows, $err] = $this->mapper->apply($model, [['subject' => 'A'], ['subject' => 'B']]);

    expect($err)->toBeNull();
    expect($rows)->toHaveCount(2);
});

it('leniently wraps a single object in many mode', function () {
    $model = ['mode' => 'many', 'item_path' => 'ticket', 'fields' => [['target' => 'name', 'source' => 'subject']]];

    [$rows, $err] = $this->mapper->apply($model, ['ticket' => ['subject' => 'Solo']]);

    expect($err)->toBeNull();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['name'])->toBe('Solo');
});

it('treats the whole node as one card in one mode', function () {
    $model = ['mode' => 'one', 'item_path' => 'opportunity', 'fields' => [['target' => 'name', 'source' => 'name']]];

    [$rows, $err] = $this->mapper->apply($model, ['opportunity' => ['name' => 'Big deal']]);

    expect($err)->toBeNull();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['name'])->toBe('Big deal');
});

it('errors when the item_path is missing', function () {
    [$rows, $err] = $this->mapper->apply(zendeskModel(), ['nope' => []]);

    expect($rows)->toBe([]);
    expect($err)->toContain('results');
});

it('errors when many mode does not find an array', function () {
    $model = ['mode' => 'many', 'item_path' => 'x', 'fields' => []];

    [$rows, $err] = $this->mapper->apply($model, ['x' => 'a scalar']);

    expect($rows)->toBe([]);
    expect($err)->not->toBeNull();
});

it('enforces the 200-card cap', function () {
    $items = array_fill(0, 201, ['subject' => 'x']);
    [$rows, $err] = $this->mapper->apply(zendeskModel(), ['results' => $items]);

    expect($rows)->toBe([]);
    expect($err)->toContain('Too many');
});

// ─── field mapping + transforms ──────────────────────────────────────────────

it('maps a full item through every transform', function () {
    $json = ['results' => [[
        'subject' => 'Refund not received',
        'description' => 'Customer says…',
        'priority' => 3,
        'labels' => 'vip, billing, vip',
        'created_at' => '2026-07-02T14:05:00Z',
        'requester' => ['email' => 'sam@acme.io'],
        'organization' => 'Acme', // unmapped → dropped
    ]]];

    [$rows, $err] = $this->mapper->apply(zendeskModel(), $json);

    expect($err)->toBeNull();
    $row = $rows[0];
    expect($row['name'])->toBe('Refund not received');
    expect($row['description'])->toBe('Customer says…');
    expect($row['priority'])->toBe('high');            // scale 3 → high
    expect($row['tags'])->toBe(['vip', 'billing']);     // split + de-dupe
    expect($row['due_date'])->toBe('2026-07-02');       // date → Y-m-d
    expect($row['column'])->toBe('Triage');             // const
    expect($row['contact']['email'])->toBe('sam@acme.io'); // dot-path + nested contact
    expect($row)->not->toHaveKey('organization');
});

it('omits fields whose source is missing (but keeps consts)', function () {
    $json = ['results' => [['subject' => 'Only a name']]];

    [$rows] = $this->mapper->apply(zendeskModel(), $json);
    $row = $rows[0];

    expect($row['name'])->toBe('Only a name');
    expect($row)->not->toHaveKey('description');
    expect($row)->not->toHaveKey('priority');
    expect($row['column'])->toBe('Triage'); // const still applied
});

it('returns an unknown-scale value as null', function () {
    $model = ['mode' => 'many', 'item_path' => '', 'fields' => [
        ['target' => 'name', 'source' => 'n'],
        ['target' => 'priority', 'source' => 'p', 'transform' => ['type' => 'scale', 'map' => ['1' => 'low']]],
    ]];

    [$rows] = $this->mapper->apply($model, [['n' => 'X', 'p' => 9]]);

    // 9 isn't in the map → null → omitted from the row.
    expect($rows[0])->not->toHaveKey('priority');
});

it('coerces a numeric string via the number transform', function () {
    $model = ['mode' => 'many', 'item_path' => '', 'fields' => [
        ['target' => 'name', 'source' => 'n'],
        ['target' => 'value', 'source' => 'amount', 'transform' => ['type' => 'number']],
    ]];

    [$rows] = $this->mapper->apply($model, [['n' => 'X', 'amount' => '1200.50']]);

    expect($rows[0]['value'])->toBe(1200.50);
});

it('makes a non-object item an empty row for per-row isolation', function () {
    $model = ['mode' => 'many', 'item_path' => '', 'fields' => [['target' => 'name', 'source' => 'n']]];

    [$rows, $err] = $this->mapper->apply($model, [['n' => 'ok'], 'not-an-object']);

    expect($err)->toBeNull();
    expect($rows)->toHaveCount(2);
    expect($rows[0]['name'])->toBe('ok');
    expect($rows[1])->toBe([]); // downstream normaliseRow rejects it with "missing name"
});
