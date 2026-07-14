<?php

declare(strict_types=1);

namespace App\Services\CardImport;

use Illuminate\Support\Carbon;

/**
 * Translates an "Opportunity Canvas" document (a sales-qualification model) into
 * the canonical card fields the importer uses. Accepts both the English key set
 * (see opportunity-canvas.en.json) and the original pt-BR keys, so a canvas
 * exported from either can be pasted straight into the JSON importer.
 *
 * The rich canvas becomes: a title from the opportunity, a structured HTML
 * description summarising every filled section, a derived priority/value/due
 * date, tags from the impact types + strategic drivers, and the linked contact.
 * Pure (no DB) so it is unit-testable in isolation.
 */
class OpportunityCanvasMapper
{
    /** True when the payload looks like an Opportunity Canvas rather than a flat card. */
    public function matches(mixed $raw): bool
    {
        if (! is_array($raw)) {
            return false;
        }
        $opportunity = $raw['opportunity'] ?? $raw['oportunidade'] ?? null;
        if (is_array($opportunity)) {
            return true;
        }

        // Fall back to the tell-tale section pair when the opportunity block is absent.
        $hasProblem = isset($raw['problem']) || isset($raw['problema']);
        $hasObjective = isset($raw['objective']) || isset($raw['objetivo']);

        return isset($raw['schemaVersion']) && $hasProblem && $hasObjective;
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?string} [fields, error]
     */
    public function toCardFields(array $raw): array
    {
        $opportunity = $this->section($raw, 'opportunity', 'oportunidade');
        $document = $this->section($raw, 'document', 'documento');

        $name = $this->str($opportunity, 'name', 'nome')
            ?: $this->str($document, 'code', 'codigo');
        if (trim($name) === '') {
            return [null, 'Opportunity Canvas is missing an opportunity name.'];
        }

        $commercial = $this->section($raw, 'commercialContext', 'contextoComercial');
        $objective = $this->section($raw, 'objective', 'objetivo');
        $gate = $this->section($raw, 'gate', 'gate');
        $score = $this->section($raw, 'score', 'score');

        $fields = [
            'name' => mb_substr(trim($name), 0, 255),
            'description' => $this->buildDescription($raw),
            'priority' => $this->derivePriority(
                $this->str($commercial, 'urgency', 'urgencia'),
                $this->str($score, 'classification', 'classificacao'),
            ),
            'value' => $this->parseMoney($this->str($commercial, 'expectedBudget', 'orcamentoPrevisto')),
            'story_points' => null,
            'section' => null,
            'section_id' => null,
            'due_date' => $this->parseDate($this->str($objective, 'desiredDeadline', 'prazoDesejado'))
                ?? $this->parseDate($this->str($gate, 'nextActionDate', 'dataProximaAcao')),
            'tags' => $this->deriveTags($raw),
            'contact' => $this->deriveContact($raw),
        ];

        return [$fields, null];
    }

    // ─── Field derivations ───────────────────────────────────────────────────

    private function derivePriority(string $urgency, string $classification): ?string
    {
        $s = mb_strtolower($urgency.' '.$classification);
        if (preg_match('/\b(alta|high|urgent|urgente|cr[ií]tica|critical)\b/u', $s)) {
            return 'high';
        }
        if (preg_match('/\b(m[eé]dia|media|medium|moderad|normal)\b/u', $s)) {
            return 'medium';
        }
        if (preg_match('/\b(baixa|low)\b/u', $s)) {
            return 'low';
        }

        return null;
    }

    /**
     * Parse a budget string into a number. Handles pt-BR grouping ("R$ 50.000,00")
     * and plain forms ("50000", "50,000.50"). Returns null when there are no digits.
     */
    private function parseMoney(string $value): ?float
    {
        $s = preg_replace('/[^\d.,]/', '', $value) ?? '';
        if ($s === '' || ! preg_match('/\d/', $s)) {
            return null;
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');
        if ($hasComma && $hasDot) {
            // The right-most separator is the decimal mark; the other groups thousands.
            $s = strrpos($s, ',') > strrpos($s, '.')
                ? str_replace(['.', ','], ['', '.'], $s)   // 50.000,00 → 50000.00
                : str_replace(',', '', $s);                // 50,000.00 → 50000.00
        } elseif ($hasComma) {
            $s = str_replace(',', '.', $s);                // 50000,00 → 50000.00
        } elseif ($hasDot && preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);                 // 50.000 → 50000 (grouping)
        }

        return is_numeric($s) ? max(0.0, (float) $s) : null;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tags from the impact types, strategic drivers, the opportunity segment, and
     * the score classification. Trimmed, capped, de-duplicated (case-insensitive).
     *
     * @return array<int, string>
     */
    private function deriveTags(array $raw): array
    {
        $impact = $this->section($raw, 'impact', 'impacto');
        $strategic = $this->section($raw, 'strategicAlignment', 'alinhamentoEstrategico');
        $opportunity = $this->section($raw, 'opportunity', 'oportunidade');
        $score = $this->section($raw, 'score', 'score');

        $candidates = array_merge(
            $this->arr($impact, 'types', 'tipos'),
            $this->arr($strategic, 'drivers', 'direcionadores'),
            [$this->str($opportunity, 'segment', 'segmento')],
            [$this->str($score, 'classification', 'classificacao')],
        );

        $out = [];
        $seen = [];
        foreach ($candidates as $tag) {
            $name = mb_substr(trim((string) $tag), 0, 50);
            $key = mb_strtolower($name);
            if ($name !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $name;
            }
        }

        return array_slice($out, 0, 15);
    }

    /**
     * @return array{name: ?string, email: ?string, phone: ?string}|null
     */
    private function deriveContact(array $raw): ?array
    {
        $contact = $this->section($raw, 'contact', 'contato');
        if ($contact === []) {
            return null;
        }

        $name = trim($this->str($contact, 'name', 'nome'));
        $emailOrPhone = trim($this->str($contact, 'emailOrPhone', 'emailOuTelefone'));

        $email = null;
        $phone = null;
        if ($emailOrPhone !== '') {
            if (str_contains($emailOrPhone, '@')) {
                $email = $emailOrPhone;
            } else {
                $phone = $emailOrPhone;
            }
        }

        if ($name === '' && $email === null && $phone === null) {
            return null;
        }

        return ['name' => $name ?: null, 'email' => $email, 'phone' => $phone];
    }

    // ─── Description (HTML for the TipTap editor) ────────────────────────────

    private function buildDescription(array $raw): string
    {
        $opportunity = $this->section($raw, 'opportunity', 'oportunidade');
        $document = $this->section($raw, 'document', 'documento');
        $contact = $this->section($raw, 'contact', 'contato');
        $problem = $this->section($raw, 'problem', 'problema');
        $impact = $this->section($raw, 'impact', 'impacto');
        $objective = $this->section($raw, 'objective', 'objetivo');
        $commercial = $this->section($raw, 'commercialContext', 'contextoComercial');
        $strategic = $this->section($raw, 'strategicAlignment', 'alinhamentoEstrategico');
        $score = $this->section($raw, 'score', 'score');
        $gate = $this->section($raw, 'gate', 'gate');

        $html = '';

        // Opportunity header line.
        $header = $this->ul([
            $this->li('Client', $this->str($opportunity, 'client', 'cliente')),
            $this->li('Segment', $this->str($opportunity, 'segment', 'segmento')),
            $this->li('Size', $this->str($opportunity, 'size', 'porte')),
            $this->li('Source', $this->str($opportunity, 'source', 'origem')),
            $this->li('Sales owner', $this->str($opportunity, 'salesOwner', 'responsavelComercial')),
            $this->li('Reference', $this->str($document, 'code', 'codigo')),
        ]);
        $html .= $this->sectionHtml('Opportunity', '', $header);

        $html .= $this->sectionHtml('Problem',
            $this->str($problem, 'description', 'descricao'),
            $this->ul([
                $this->li('Current handling', $this->str($problem, 'currentHandling', 'tratamentoAtual')),
                $this->li('Affected', $this->str($problem, 'affected', 'afetados')),
                $this->li('Frequency', $this->str($problem, 'frequency', 'frequencia')),
                $this->li('Consequence of inaction', $this->str($problem, 'consequenceOfInaction', 'consequenciaDaInacao')),
            ]),
        );

        $html .= $this->sectionHtml('Impact',
            $this->str($impact, 'description', 'descricao'),
            $this->ul([
                $this->li('Types', implode(', ', $this->arr($impact, 'types', 'tipos'))),
                $this->li('Evidence', $this->str($impact, 'evidence', 'evidencias')),
                $this->li('Level', $this->str($impact, 'level', 'nivel')),
            ]),
        );

        $html .= $this->sectionHtml('Objective',
            $this->str($objective, 'expectedResult', 'resultadoEsperado'),
            $this->ul([
                $this->li('Solution hypothesis', $this->str($objective, 'solutionHypothesis', 'hipoteseSolucao')),
                $this->li('Success criterion', $this->str($objective, 'successCriterion', 'criterioDeSucesso')),
                $this->li('Indicators', $this->str($objective, 'indicators', 'indicadores')),
                $this->li('Desired deadline', $this->str($objective, 'desiredDeadline', 'prazoDesejado')),
            ]),
        );

        $html .= $this->sectionHtml('Commercial context', '',
            $this->ul([
                $this->li('Expected budget', $this->str($commercial, 'expectedBudget', 'orcamentoPrevisto')),
                $this->li('Budget range', $this->str($commercial, 'budgetRange', 'faixaOrcamento')),
                $this->li('Urgency', $this->str($commercial, 'urgency', 'urgencia')),
                $this->li('Decision maker', $this->str($commercial, 'decisionMaker', 'decisor')),
                $this->li('Decision maker identified', $this->str($commercial, 'decisionMakerIdentified', 'decisorIdentificado')),
                $this->li('Internal sponsor', $this->str($commercial, 'internalSponsor', 'patrocinadorInterno')),
                $this->li('Evaluating competitors', $this->str($commercial, 'evaluatingCompetitors', 'avaliandoConcorrentes')),
                $this->li('Formal procurement process', $this->str($commercial, 'formalProcurementProcess', 'processoFormalContratacao')),
            ]),
        );

        $html .= $this->sectionHtml('Strategic alignment',
            $this->str($strategic, 'rationale', 'justificativa'),
            $this->ul([
                $this->li('Recurrence', $this->str($strategic, 'recurrence', 'recorrencia')),
                $this->li('Reuse potential', $this->str($strategic, 'reusePotential', 'potencialReutilizacao')),
                $this->li('Partnership potential', $this->str($strategic, 'partnershipPotential', 'potencialParceria')),
                $this->li('Strategic competency', $this->str($strategic, 'strategicCompetency', 'competenciaEstrategica')),
                $this->li('Drivers', implode(', ', $this->arr($strategic, 'drivers', 'direcionadores'))),
                $this->li('Risks', $this->str($strategic, 'risks', 'riscos')),
            ]),
        );

        $html .= $this->sectionHtml('Score', '', $this->scoreList($score));

        $html .= $this->sectionHtml('Gate',
            $this->str($gate, 'decision', 'decisao'),
            $this->ul([
                $this->li('Rationale', $this->str($gate, 'rationale', 'justificativa')),
                $this->li('Pending information', $this->str($gate, 'pendingInformation', 'informacoesPendentes')),
                $this->li('Next action', $this->str($gate, 'nextAction', 'proximaAcao')),
                $this->li('Owner', $this->str($gate, 'owner', 'responsavel')),
                $this->li('Next action date', $this->str($gate, 'nextActionDate', 'dataProximaAcao')),
                $this->li('Approver', $this->str($gate, 'approver', 'aprovador')),
            ]),
        );

        $html .= $this->sectionHtml('Contact', '',
            $this->ul([
                $this->li('Name', $this->str($contact, 'name', 'nome')),
                $this->li('Role', $this->str($contact, 'role', 'cargo')),
                $this->li('Email / phone', $this->str($contact, 'emailOrPhone', 'emailOuTelefone')),
            ]),
        );

        return $html;
    }

    private function scoreList(array $score): string
    {
        $rows = [
            'Problem clarity' => ['problemClarity', 'clarezaProblema'],
            'Customer value' => ['customerValue', 'valorCliente'],
            'Customer engagement' => ['customerEngagement', 'engajamentoCliente'],
            'Financial potential' => ['financialPotential', 'potencialFinanceiro'],
            'Urgency' => ['urgency', 'urgencia'],
            'Strategic alignment' => ['strategicAlignment', 'alinhamentoEstrategico'],
            'Scale potential' => ['scalePotential', 'potencialEscala'],
            'Perceived feasibility' => ['perceivedFeasibility', 'viabilidadePercebida'],
        ];

        $items = [];
        foreach ($rows as $label => [$en, $pt]) {
            $n = $this->num($score, $en, $pt);
            if ($n !== null && $n > 0) {
                $items[] = $this->li($label, (string) $n);
            }
        }
        $result = $this->num($score, 'result', 'resultado');
        if ($result !== null && $result > 0) {
            $items[] = $this->li('Result', (string) $result);
        }
        $items[] = $this->li('Classification', $this->str($score, 'classification', 'classificacao'));

        return $this->ul($items);
    }

    // ─── Section / value accessors ───────────────────────────────────────────

    /** @return array<string, mixed> */
    private function section(array $raw, string $en, string $pt): array
    {
        $s = $raw[$en] ?? $raw[$pt] ?? null;

        return is_array($s) ? $s : [];
    }

    private function str(array $section, string $en, string $pt): string
    {
        $v = $section[$en] ?? $section[$pt] ?? null;

        return is_scalar($v) ? trim((string) $v) : '';
    }

    private function num(array $section, string $en, string $pt): ?float
    {
        $v = $section[$en] ?? $section[$pt] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }

    /** @return array<int, string> */
    private function arr(array $section, string $en, string $pt): array
    {
        $v = $section[$en] ?? $section[$pt] ?? null;
        if (! is_array($v)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($x) => is_scalar($x) ? trim((string) $x) : '',
            $v,
        ), fn ($x) => $x !== ''));
    }

    // ─── HTML builders (escaped) ─────────────────────────────────────────────

    /** A section is emitted only when it has a paragraph and/or a non-empty list. */
    private function sectionHtml(string $title, string $paragraph, string $list): string
    {
        $body = $this->p($paragraph).$list;

        return $body === '' ? '' : '<h3>'.$this->esc($title).'</h3>'.$body;
    }

    private function p(string $text): string
    {
        $text = trim($text);

        return $text === '' ? '' : '<p>'.nl2br($this->esc($text)).'</p>';
    }

    private function li(string $label, string $value): string
    {
        $value = trim($value);

        return $value === '' ? '' : '<li><strong>'.$this->esc($label).':</strong> '.$this->esc($value).'</li>';
    }

    /** @param array<int, string> $items */
    private function ul(array $items): string
    {
        $items = array_filter($items, fn ($i) => $i !== '');

        return $items === [] ? '' : '<ul>'.implode('', $items).'</ul>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
