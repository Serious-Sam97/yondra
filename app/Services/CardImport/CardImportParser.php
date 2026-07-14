<?php

declare(strict_types=1);

namespace App\Services\CardImport;

use Illuminate\Support\Carbon;

/**
 * Normalises a user-supplied "custom JSON model" into the canonical card fields
 * the CardService expects. The importer (YON-121) deliberately accepts a range
 * of field spellings — title/name, column/status/section, labels/tags — so users
 * can paste JSON from other tools without hand-editing every key.
 *
 * Section is left as a raw hint (`section` name or `section_id`) here; the
 * controller resolves it against the board's real columns since that needs a DB
 * lookup. This class stays pure so it is trivially unit-testable.
 */
class CardImportParser
{
    /** Hard cap so a single request can't spawn an unbounded number of cards. */
    public const MAX_CARDS = 200;

    private OpportunityCanvasMapper $canvas;

    public function __construct(?OpportunityCanvasMapper $canvas = null)
    {
        // Optional so `new CardImportParser` (unit tests) still works; the
        // container injects the shared instance in the controller.
        $this->canvas = $canvas ?? new OpportunityCanvasMapper;
    }

    /** Accepted spellings for each field, in priority order. */
    private const ALIASES = [
        'name' => ['name', 'title', 'summary', 'subject'],
        'description' => ['description', 'desc', 'body', 'details', 'content', 'notes'],
        'priority' => ['priority'],
        'due_date' => ['due_date', 'due', 'dueDate', 'deadline'],
        'value' => ['value', 'amount', 'deal_value', 'dealValue'],
        'story_points' => ['story_points', 'storyPoints', 'points', 'estimate'],
        'section' => ['section', 'column', 'status', 'stage', 'list'],
        'section_id' => ['section_id', 'sectionId'],
        'tags' => ['tags', 'labels', 'tag'],
    ];

    /**
     * Pull the list of raw card objects out of the request payload. Accepts a
     * bare array of cards, a `{ "cards": [...] }` envelope, or a single card
     * object. Returns [rows, error] where error is a human message when the
     * shape itself is unusable (rows is [] in that case).
     *
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}
     */
    public function extractRows(mixed $payload): array
    {
        if (is_array($payload) && array_key_exists('cards', $payload)) {
            $payload = $payload['cards'];
        }

        // A single card object (assoc array) → wrap it.
        if (is_array($payload) && $this->isAssoc($payload)) {
            $payload = [$payload];
        }

        if (! is_array($payload)) {
            return [[], 'Expected a JSON array of cards or a { "cards": [...] } object.'];
        }

        if (count($payload) === 0) {
            return [[], 'No cards found in the provided JSON.'];
        }

        if (count($payload) > self::MAX_CARDS) {
            return [[], 'Too many cards: '.count($payload).' provided, the limit is '.self::MAX_CARDS.'.'];
        }

        return [array_values($payload), null];
    }

    /**
     * Normalise one raw card object into canonical fields. Returns [fields, error]
     * where error is set (and fields null) when the row can't produce a card —
     * currently only a missing/blank name.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    public function normaliseRow(mixed $raw): array
    {
        if (! is_array($raw) || ! $this->isAssoc($raw)) {
            return [null, 'Each card must be a JSON object.'];
        }

        // An Opportunity Canvas document is translated by its dedicated mapper.
        if ($this->canvas->matches($raw)) {
            return $this->canvas->toCardFields($raw);
        }

        $name = $this->firstString($raw, self::ALIASES['name']);
        if ($name === null || trim($name) === '') {
            return [null, 'Card is missing a name/title.'];
        }

        $fields = [
            'name' => mb_substr(trim($name), 0, 255),
            'description' => $this->firstString($raw, self::ALIASES['description']) ?? '',
            'priority' => $this->normalisePriority($this->firstScalar($raw, self::ALIASES['priority'])),
            'due_date' => $this->normaliseDate($this->firstScalar($raw, self::ALIASES['due_date'])),
            'value' => $this->normaliseNumeric($this->firstScalar($raw, self::ALIASES['value'])),
            'story_points' => $this->normaliseInt($this->firstScalar($raw, self::ALIASES['story_points'])),
            'section' => $this->firstString($raw, self::ALIASES['section']),
            'section_id' => $this->normaliseInt($this->firstScalar($raw, self::ALIASES['section_id'])),
            'tags' => $this->normaliseTags($this->firstValue($raw, self::ALIASES['tags'])),
            'contact' => $this->normaliseContact($this->firstValue($raw, ['contact', 'contato'])),
        ];

        return [$fields, null];
    }

    /** True when the array has at least one non-integer (string) key. */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true; // treat {} as an (empty) object, not a list
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * A flat card's optional nested contact `{name,email,phone}`. Returns null
     * when absent or entirely blank so the importer leaves the card unlinked.
     *
     * @return array{name: ?string, email: ?string, phone: ?string}|null
     */
    private function normaliseContact(mixed $contact): ?array
    {
        if (! is_array($contact) || ! $this->isAssoc($contact)) {
            return null;
        }

        $name = trim((string) ($this->firstString($contact, ['name', 'nome']) ?? ''));
        $email = trim((string) ($this->firstString($contact, ['email', 'e-mail']) ?? ''));
        $phone = trim((string) ($this->firstString($contact, ['phone', 'telefone', 'tel']) ?? ''));

        if ($name === '' && $email === '' && $phone === '') {
            return null;
        }

        return ['name' => $name ?: null, 'email' => $email ?: null, 'phone' => $phone ?: null];
    }

    /** First present key's value, regardless of type. */
    private function firstValue(array $raw, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw)) {
                return $raw[$key];
            }
        }

        return null;
    }

    /** First present key whose value is a scalar (string/number/bool). */
    private function firstScalar(array $raw, array $keys): mixed
    {
        $value = $this->firstValue($raw, $keys);

        return is_scalar($value) ? $value : null;
    }

    private function firstString(array $raw, array $keys): ?string
    {
        $value = $this->firstScalar($raw, $keys);

        return $value === null ? null : (string) $value;
    }

    private function normalisePriority(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $v = mb_strtolower(trim((string) $value));

        return in_array($v, ['low', 'medium', 'high'], true) ? $v : null;
    }

    private function normaliseDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normaliseNumeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private function normaliseInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            return (int) trim($value);
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Accept tags as a JSON array of strings or a comma-separated string. Trims,
     * drops blanks, de-duplicates while preserving order.
     *
     * @return array<int, string>
     */
    private function normaliseTags(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $tag) {
            if (! is_scalar($tag)) {
                continue;
            }
            $name = trim((string) $tag);
            if ($name !== '' && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }
}
