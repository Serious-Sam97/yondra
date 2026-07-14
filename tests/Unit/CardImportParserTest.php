<?php

use App\Services\CardImport\CardImportParser;

beforeEach(function () {
    $this->parser = new CardImportParser;
});

// ─── extractRows: accepted top-level shapes ──────────────────────────────────

it('accepts a bare array of card objects', function () {
    [$rows, $err] = $this->parser->extractRows([['name' => 'A'], ['name' => 'B']]);

    expect($err)->toBeNull();
    expect($rows)->toHaveCount(2);
});

it('unwraps a { cards: [...] } envelope', function () {
    [$rows, $err] = $this->parser->extractRows(['cards' => [['name' => 'A']]]);

    expect($err)->toBeNull();
    expect($rows)->toHaveCount(1);
});

it('wraps a single card object into a one-row list', function () {
    [$rows, $err] = $this->parser->extractRows(['name' => 'Solo', 'priority' => 'high']);

    expect($err)->toBeNull();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['name'])->toBe('Solo');
});

it('rejects a non-array cards value', function () {
    [$rows, $err] = $this->parser->extractRows(['cards' => 'nope']);

    expect($rows)->toBe([]);
    expect($err)->not->toBeNull();
});

it('rejects a batch over the row cap', function () {
    $big = array_fill(0, CardImportParser::MAX_CARDS + 1, ['name' => 'x']);
    [$rows, $err] = $this->parser->extractRows($big);

    expect($rows)->toBe([]);
    expect($err)->toContain('Too many cards');
});

// ─── normaliseRow: field mapping + aliases ───────────────────────────────────

it('requires a name and rejects a row without one', function () {
    [$fields, $err] = $this->parser->normaliseRow(['description' => 'orphan']);

    expect($fields)->toBeNull();
    expect($err)->toContain('name');
});

it('accepts title/summary/subject as aliases for name', function () {
    expect($this->parser->normaliseRow(['title' => 'T'])[0]['name'])->toBe('T');
    expect($this->parser->normaliseRow(['summary' => 'S'])[0]['name'])->toBe('S');
    expect($this->parser->normaliseRow(['subject' => 'U'])[0]['name'])->toBe('U');
});

it('maps every optional field with lenient spellings and coercion', function () {
    [$fields] = $this->parser->normaliseRow([
        'title' => '  Trimmed  ',
        'body' => 'the description',
        'priority' => 'HIGH',
        'due' => '2026-09-01',
        'amount' => '1200.50',
        'points' => '5',
        'column' => 'In Progress',
    ]);

    expect($fields['name'])->toBe('Trimmed');
    expect($fields['description'])->toBe('the description');
    expect($fields['priority'])->toBe('high');
    expect($fields['due_date'])->toBe('2026-09-01');
    expect($fields['value'])->toBe(1200.50);
    expect($fields['story_points'])->toBe(5);
    expect($fields['section'])->toBe('In Progress');
});

it('nulls out an invalid priority and an unparseable date', function () {
    [$fields] = $this->parser->normaliseRow([
        'name' => 'X',
        'priority' => 'urgent', // not one of low|medium|high
        'due_date' => 'not a date',
    ]);

    expect($fields['priority'])->toBeNull();
    expect($fields['due_date'])->toBeNull();
});

it('accepts tags as an array or a comma-separated string, de-duplicated', function () {
    [$arr] = $this->parser->normaliseRow(['name' => 'A', 'tags' => ['bug', 'bug', ' urgent ']]);
    expect($arr['tags'])->toBe(['bug', 'urgent']);

    [$str] = $this->parser->normaliseRow(['name' => 'B', 'labels' => 'bug, backend ,']);
    expect($str['tags'])->toBe(['bug', 'backend']);
});

it('defaults tags to an empty array when absent or non-scalar', function () {
    [$none] = $this->parser->normaliseRow(['name' => 'A']);
    expect($none['tags'])->toBe([]);

    [$bad] = $this->parser->normaliseRow(['name' => 'B', 'tags' => [['nested' => 1]]]);
    expect($bad['tags'])->toBe([]);
});

it('reads section_id only from digits and ignores garbage', function () {
    expect($this->parser->normaliseRow(['name' => 'A', 'section_id' => 42])[0]['section_id'])->toBe(42);
    expect($this->parser->normaliseRow(['name' => 'B', 'sectionId' => '7'])[0]['section_id'])->toBe(7);
    expect($this->parser->normaliseRow(['name' => 'C', 'section_id' => 'abc'])[0]['section_id'])->toBeNull();
});

it('rejects a non-object row', function () {
    [$fields, $err] = $this->parser->normaliseRow('just a string');

    expect($fields)->toBeNull();
    expect($err)->toContain('object');
});

it('truncates an over-long name to 255 chars', function () {
    [$fields] = $this->parser->normaliseRow(['name' => str_repeat('x', 300)]);

    expect(mb_strlen($fields['name']))->toBe(255);
});
