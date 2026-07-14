<?php

use App\Services\Intake\IntakeSubmissionParser;

beforeEach(function () {
    $this->parser = new IntakeSubmissionParser;
});

/** Wrap answers as a JotForm-style payload (rawRequest JSON + submissionID). */
function payload(array $answers, string $submissionId = '900'): array
{
    return ['submissionID' => $submissionId, 'rawRequest' => json_encode($answers)];
}

// ─── Heuristics (empty map) ──────────────────────────────────────────────────

it('maps common fields heuristically with no configured map', function () {
    $out = $this->parser->parse(payload([
        'q1_subject' => 'Kitchen remodel',
        'q2_name' => ['first' => 'Jane', 'last' => 'Doe'],
        'q3_email' => 'jane@example.com',
        'q4_phone' => '+55 11 90000',
        'q5_message' => 'Please quote a remodel.',
    ]));

    expect($out['name'])->toBe('Kitchen remodel');
    expect($out['contact'])->toBe(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '+55 11 90000']);
    expect($out['description'])->toContain('Please quote a remodel.');
    // Attributes only come from an explicit map.
    expect($out['value'])->toBeNull();
    expect($out['tags'])->toBe([]);
});

it('ignores JotForm housekeeping fields', function () {
    $out = $this->parser->parse([
        'formID' => '251', 'submissionID' => '77', 'pretty' => 'A:B', 'ip' => '1.2.3.4',
        'rawRequest' => json_encode(['q1_subject' => 'Clean']),
    ]);

    expect($out['name'])->toBe('Clean');
    expect(strtolower($out['description']))->not->toContain('form id');
    expect($out['description'])->not->toContain('77');
    expect($out['description'])->not->toContain('1.2.3.4');
});

it('falls back to a submission reference when nothing is title-worthy', function () {
    $out = $this->parser->parse(payload(['q9_budget' => '1000'], '4242'));
    expect($out['name'])->toBe('Intake submission #4242');
});

it('extracts file URLs and keeps them out of the description text', function () {
    $out = $this->parser->parse(payload([
        'q1_subject' => 'With files',
        'q2_upload' => ['https://www.jotform.com/uploads/x/y/z/plan.pdf'],
    ]));

    expect($out['files'])->toBe(['https://www.jotform.com/uploads/x/y/z/plan.pdf']);
    expect($out['description'])->not->toContain('jotform.com');
});

// ─── Explicit mapping ────────────────────────────────────────────────────────

it('routes fields to the configured targets', function () {
    $out = $this->parser->parse(payload([
        'q1_project' => 'Loft conversion',
        'q2_budget' => 'R$25.000,00',
        'q3_service' => 'Design, Build',
        'q4_urgency' => 'Very urgent',
        'q5_points' => 'about 8 points',
        'q6_deadline' => '2026-09-15',
    ]), [
        ['source' => 'project', 'target' => 'title'],
        ['source' => 'budget', 'target' => 'value'],
        ['source' => 'service', 'target' => 'tags'],
        ['source' => 'urgency', 'target' => 'priority'],
        ['source' => 'points', 'target' => 'story_points'],
        ['source' => 'deadline', 'target' => 'due_date'],
    ]);

    expect($out['name'])->toBe('Loft conversion');
    expect($out['value'])->toBe(25000.0);
    expect($out['tags'])->toBe(['Design', 'Build']);
    expect($out['priority'])->toBe('high');
    expect($out['story_points'])->toBe(8);
    expect($out['due_date'])->toBe('2026-09-15');
});

it('maps contact fields and an explicit description, and drops ignored fields', function () {
    $out = $this->parser->parse(payload([
        'q1_who' => 'Alex Stone',
        'q2_mail' => 'alex@example.com',
        'q3_brief' => 'Full brief here',
        'q4_internal' => 'do-not-show',
        'q5_title' => 'Website build',
    ]), [
        ['source' => 'who', 'target' => 'contact_name'],
        ['source' => 'mail', 'target' => 'contact_email'],
        ['source' => 'brief', 'target' => 'description'],
        ['source' => 'internal', 'target' => 'ignore'],
        ['source' => 'title', 'target' => 'title'],
    ]);

    expect($out['name'])->toBe('Website build');
    expect($out['contact']['name'])->toBe('Alex Stone');
    expect($out['contact']['email'])->toBe('alex@example.com');
    expect($out['description'])->toContain('Full brief here');
    // Ignored + consumed fields never surface.
    expect($out['description'])->not->toContain('do-not-show');
});

it('preserves heuristics for targets the map does not cover', function () {
    $out = $this->parser->parse(payload([
        'q1_subject' => 'Heuristic title',
        'q2_budget' => '5000',
        'q3_name' => ['first' => 'Jo', 'last' => 'Lee'],
    ]), [
        ['source' => 'budget', 'target' => 'value'],
    ]);

    expect($out['name'])->toBe('Heuristic title');   // heuristic subject
    expect($out['value'])->toBe(5000.0);              // mapped
    expect($out['contact']['name'])->toBe('Jo Lee');  // heuristic contact
});

it('appends unmapped fields to a Submission details block', function () {
    $out = $this->parser->parse(payload([
        'q1_title' => 'Job',
        'q2_budgetRange' => '$5k-$10k',
        'q3_preferredContact' => 'Evenings',
    ]), [['source' => 'title', 'target' => 'title']]);

    expect($out['description'])->toContain('Submission details');
    expect($out['description'])->toContain('Budget Range');
    expect($out['description'])->toContain('Preferred Contact');
});

it('ignores mapping rules with a blank source or unknown target', function () {
    $out = $this->parser->parse(payload(['q1_subject' => 'Keep']), [
        ['source' => '', 'target' => 'title'],
        ['source' => 'subject', 'target' => 'bogus_target'],
    ]);
    // Neither rule applies; heuristic subject wins for the title.
    expect($out['name'])->toBe('Keep');
});

// ─── Money parsing variants (via a value mapping) ────────────────────────────

it('parses money in BR, US, suffix and plain forms', function (string $raw, ?float $expected) {
    $out = $this->parser->parse(payload(['q1_amount' => $raw]), [
        ['source' => 'amount', 'target' => 'value'],
    ]);
    expect($out['value'])->toBe($expected);
})->with([
    ['R$1.500,00', 1500.0],
    ['$1,500.50', 1500.5],
    ['1.500', 1500.0],   // BR thousands, no decimals
    ['1500', 1500.0],
    ['R$20k', 20000.0],
    ['2M', 2000000.0],
    ['no digits here', null],
]);

it('maps priority synonyms including Portuguese', function (string $raw, ?string $expected) {
    $out = $this->parser->parse(payload(['q1_pri' => $raw]), [
        ['source' => 'pri', 'target' => 'priority'],
    ]);
    expect($out['priority'])->toBe($expected);
})->with([
    ['Very urgent', 'high'],
    ['alta', 'high'],
    ['baixa', 'low'],
    ['normal', 'medium'],
    ['whatever', null],
]);

it('splits a tag field on commas, semicolons and slashes', function () {
    $out = $this->parser->parse(payload(['q1_svc' => 'seo; design / web, print']), [
        ['source' => 'svc', 'target' => 'tags'],
    ]);
    expect($out['tags'])->toBe(['seo', 'design', 'web', 'print']);
});
