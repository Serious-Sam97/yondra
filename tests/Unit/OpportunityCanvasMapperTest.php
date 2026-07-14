<?php

use App\Services\CardImport\CardImportParser;
use App\Services\CardImport\OpportunityCanvasMapper;

beforeEach(function () {
    $this->mapper = new OpportunityCanvasMapper;
});

/** A fully-filled English canvas. */
function enCanvas(array $overrides = []): array
{
    return array_replace_recursive([
        'schemaVersion' => '1.1',
        'document' => ['code' => 'OC-2026-009', 'status' => 'draft'],
        'opportunity' => [
            'name' => 'Acme warehouse rollout',
            'client' => 'Acme Corp',
            'segment' => 'Logistics',
            'size' => 'Enterprise',
            'source' => 'Inbound',
            'salesOwner' => 'Sam',
        ],
        'contact' => ['name' => 'Jane Doe', 'role' => 'COO', 'emailOrPhone' => 'jane@acme.com'],
        'problem' => [
            'description' => 'Manual stock counts cause errors.',
            'currentHandling' => 'Spreadsheets',
            'affected' => 'Warehouse team',
            'frequency' => 'Daily',
            'consequenceOfInaction' => 'Lost inventory',
        ],
        'impact' => ['types' => ['Financial', 'Operational'], 'description' => 'High cost', 'level' => 'High'],
        'objective' => [
            'expectedResult' => 'Cut errors 90%',
            'successCriterion' => '<1% error rate',
            'desiredDeadline' => '2026-10-01',
        ],
        'commercialContext' => ['expectedBudget' => 'R$ 120.000,00', 'urgency' => 'Alta'],
        'strategicAlignment' => ['rationale' => 'Fits vertical', 'drivers' => ['Expansion']],
        'score' => ['problemClarity' => 8, 'result' => 42, 'classification' => 'Priority'],
        'gate' => ['decision' => 'Advance', 'nextActionDate' => '2026-08-20'],
    ], $overrides);
}

it('recognises an Opportunity Canvas by its opportunity section (EN and PT keys)', function () {
    expect($this->mapper->matches(enCanvas()))->toBeTrue();
    expect($this->mapper->matches(['oportunidade' => ['nome' => 'X']]))->toBeTrue();
});

it('does not mistake a flat card for a canvas', function () {
    expect($this->mapper->matches(['name' => 'Just a card', 'priority' => 'high']))->toBeFalse();
});

it('the parser routes a canvas through the mapper', function () {
    // End-to-end through the public parser to prove the wiring, not just the mapper.
    [$fields, $err] = (new CardImportParser)->normaliseRow(enCanvas());

    expect($err)->toBeNull();
    expect($fields['name'])->toBe('Acme warehouse rollout');
});

it('maps opportunity name, priority, value, due date, and tags', function () {
    [$fields] = $this->mapper->toCardFields(enCanvas());

    expect($fields['name'])->toBe('Acme warehouse rollout');
    expect($fields['priority'])->toBe('high');            // from urgency "Alta"
    expect($fields['value'])->toBe(120000.0);             // pt-BR "R$ 120.000,00"
    expect($fields['due_date'])->toBe('2026-10-01');      // objective desired deadline
    // Tags = impact types + strategic drivers + segment + classification.
    expect($fields['tags'])->toContain('Financial', 'Operational', 'Expansion', 'Logistics', 'Priority');
});

it('extracts the contact, detecting email vs phone from the combined field', function () {
    [$email] = $this->mapper->toCardFields(enCanvas());
    expect($email['contact'])->toBe(['name' => 'Jane Doe', 'email' => 'jane@acme.com', 'phone' => null]);

    [$phone] = $this->mapper->toCardFields(enCanvas([
        'contact' => ['emailOrPhone' => '+55 11 99999-8888'],
    ]));
    expect($phone['contact']['phone'])->toBe('+55 11 99999-8888');
    expect($phone['contact']['email'])->toBeNull();
});

it('builds an HTML description summarising the filled sections', function () {
    [$fields] = $this->mapper->toCardFields(enCanvas());
    $html = $fields['description'];

    expect($html)->toContain('<h3>Problem</h3>');
    expect($html)->toContain('Manual stock counts cause errors.');
    expect($html)->toContain('<h3>Objective</h3>');
    expect($html)->toContain('<strong>Success criterion:</strong>');
    expect($html)->toContain('<h3>Score</h3>');
    // The XSS-y success criterion "<1% error rate" must be escaped, not raw.
    expect($html)->toContain('&lt;1% error rate');
    expect($html)->not->toContain('<1% error rate');
});

it('falls back to the document code when the opportunity has no name', function () {
    [$fields, $err] = $this->mapper->toCardFields(enCanvas([
        'opportunity' => ['name' => ''],
    ]));

    expect($err)->toBeNull();
    expect($fields['name'])->toBe('OC-2026-009');
});

it('errors when neither an opportunity name nor a document code is present', function () {
    [$fields, $err] = $this->mapper->toCardFields([
        'opportunity' => ['name' => ''],
        'document' => ['code' => ''],
    ]);

    expect($fields)->toBeNull();
    expect($err)->toContain('opportunity name');
});

it('reads the original pt-BR keys just as well as English', function () {
    [$fields, $err] = $this->mapper->toCardFields([
        'oportunidade' => ['nome' => 'Projeto X', 'segmento' => 'Varejo'],
        'contextoComercial' => ['urgencia' => 'Baixa', 'orcamentoPrevisto' => '5000'],
        'impacto' => ['tipos' => ['Financeiro']],
        'contato' => ['nome' => 'João', 'emailOuTelefone' => 'joao@x.com'],
    ]);

    expect($err)->toBeNull();
    expect($fields['name'])->toBe('Projeto X');
    expect($fields['priority'])->toBe('low');
    expect($fields['value'])->toBe(5000.0);
    expect($fields['tags'])->toContain('Financeiro', 'Varejo');
    expect($fields['contact']['email'])->toBe('joao@x.com');
});
