<?php

declare(strict_types=1);

namespace App\Services\CardImport;

use Illuminate\Support\Carbon;

/**
 * Applies a custom import model (YON-122) to raw JSON, producing the canonical
 * card rows the flat importer already understands. A model is a remapper that
 * runs *in front of* CardImportParser: it locates the items in an arbitrary
 * shape, then rewrites each item's keys/values onto card fields via a list of
 * source→target rules (with optional transforms). The rows it returns are then
 * fed through the same normalise + column-resolution + create pipeline the flat
 * path uses, so all the row-level error isolation and tag/contact handling is
 * reused rather than duplicated.
 *
 * Definition shape (persisted on ImportModel):
 *   mode:      'many' (item_path → array, one card each) | 'one' (whole node = one card)
 *   item_path: dot-path to the items, e.g. "data.tickets"; null/'' = the root
 *   fields:    [{ target, source?, transform? }]
 *
 * Targets: name|description|priority|due_date|story_points|value|tags|column|
 *          contact_name|contact_email|contact_phone
 * Transforms: {type:'const',value} | {type:'split',delimiter} | {type:'scale',map} |
 *             {type:'date'} | {type:'number'} | {type:'none'} (or omitted)
 */
class ImportModelMapper
{
    /** target → canonical card key the parser reads (contact_* nest under `contact`). */
    private const CONTACT_TARGETS = [
        'contact_name' => 'name',
        'contact_email' => 'email',
        'contact_phone' => 'phone',
    ];

    private const CARD_TARGETS = [
        'name',
        'description',
        'priority',
        'due_date',
        'story_points',
        'value',
        'tags',
        'column',
    ];

    /**
     * Locate the items and rewrite each into a canonical card row. Returns
     * [rows, error] mirroring CardImportParser::extractRows so the controller
     * can surface a whole-payload 422 uniformly. Non-object items become empty
     * rows so the per-row "missing name" error isolates them.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}
     */
    public function apply(array $definition, mixed $json): array
    {
        $mode = ($definition['mode'] ?? 'many') === 'one' ? 'one' : 'many';
        $path = $definition['item_path'] ?? null;
        $rules = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];

        $node = ($path === null || $path === '') ? $json : $this->dotGet($json, (string) $path);
        if ($node === null) {
            return [[], $path ? "No data found at \"{$path}\"." : 'No data found in the JSON.'];
        }

        if ($mode === 'one') {
            if (! $this->isAssoc($node)) {
                return [[], 'Expected a JSON object for a single-card model.'];
            }
            $items = [$node];
        } else {
            if ($this->isList($node)) {
                $items = array_values($node);
            } elseif ($this->isAssoc($node)) {
                $items = [$node]; // lenient: a lone object is a one-item batch
            } else {
                return [[], 'Expected an array of items'.($path ? " at \"{$path}\"." : '.')];
            }
        }

        if (count($items) === 0) {
            return [[], 'No items to import.'];
        }
        if (count($items) > CardImportParser::MAX_CARDS) {
            return [[], 'Too many cards: '.count($items).' found, the limit is '.CardImportParser::MAX_CARDS.'.'];
        }

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->isAssoc($item) ? $this->buildRow($item, $rules) : [];
        }

        return [$rows, null];
    }

    /**
     * Build one canonical card row from an item and the model's field rules.
     *
     * @param  array<int, mixed>  $rules
     * @return array<string, mixed>
     */
    private function buildRow(array $item, array $rules): array
    {
        $row = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $target = $rule['target'] ?? null;
            if (! is_string($target) || $target === '') {
                continue;
            }

            $source = $rule['source'] ?? null;
            $raw = (is_string($source) && $source !== '')
                ? $this->dotGet($item, $source)
                : null;

            $value = $this->applyTransform($raw, $rule['transform'] ?? null);

            // Skip empties so a rule that maps nothing doesn't blank a field; a
            // const transform is always meaningful even when it resolves to "".
            $isConst = is_array($rule['transform'] ?? null)
                && ($rule['transform']['type'] ?? null) === 'const';
            if ($value === null && ! $isConst) {
                continue;
            }

            $this->assign($row, $target, $value);
        }

        return $row;
    }

    /**
     * Place a mapped value onto the canonical row under the right key, nesting
     * contact_* targets into a `contact` array the parser reads.
     */
    private function assign(array &$row, string $target, mixed $value): void
    {
        if (isset(self::CONTACT_TARGETS[$target])) {
            $row['contact'] ??= [];
            $row['contact'][self::CONTACT_TARGETS[$target]] = $value;

            return;
        }

        if (in_array($target, self::CARD_TARGETS, true)) {
            $row[$target] = $value;
        }
        // Unknown targets are ignored — validation rejects them on save anyway.
    }

    /**
     * Apply a field transform. Returns the mapped value (null when it can't
     * produce one); the downstream parser re-coerces types, so producing the
     * canonical spelling (e.g. "low"/"medium"/"high", an array of tags) is enough.
     */
    private function applyTransform(mixed $raw, mixed $transform): mixed
    {
        $type = is_array($transform) ? ($transform['type'] ?? 'none') : 'none';

        return match ($type) {
            'const' => is_array($transform) ? ($transform['value'] ?? null) : null,
            'split' => $this->transformSplit($raw, is_array($transform) ? ($transform['delimiter'] ?? ',') : ','),
            'scale' => $this->transformScale($raw, is_array($transform) ? ($transform['map'] ?? []) : []),
            'date' => $this->transformDate($raw),
            'number' => $this->transformNumber($raw),
            default => $raw,
        };
    }

    /**
     * Split a delimited string into a de-duplicated list of non-blank tags. An
     * array passes through; anything else yields an empty list.
     *
     * @return array<int, string>
     */
    private function transformSplit(mixed $raw, mixed $delimiter): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }
        if (! is_string($raw)) {
            return [];
        }
        $delim = is_string($delimiter) && $delimiter !== '' ? $delimiter : ',';
        $out = [];
        foreach (explode($delim, $raw) as $part) {
            $t = trim($part);
            if ($t !== '' && ! in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** Look a raw value up in a lookup table (e.g. numeric severity → priority word). */
    private function transformScale(mixed $raw, mixed $map): mixed
    {
        if (! is_array($map) || ! is_scalar($raw)) {
            return null;
        }
        $key = trim((string) $raw);

        return $map[$key] ?? null;
    }

    private function transformDate(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function transformNumber(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        if (is_string($raw) && is_numeric(trim($raw))) {
            return (float) trim($raw);
        }

        return null;
    }

    /**
     * Navigate a dot-path (e.g. "requester.email" or "items.0.name") through
     * nested objects/lists. Returns null the moment a segment is missing.
     */
    private function dotGet(mixed $data, string $path): mixed
    {
        $node = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node)) {
                return null;
            }
            if (array_key_exists($segment, $node)) {
                $node = $node[$segment];
            } elseif (ctype_digit($segment) && array_key_exists((int) $segment, $node)) {
                $node = $node[(int) $segment];
            } else {
                return null;
            }
        }

        return $node;
    }

    /** A JSON object: a non-empty array with at least one string key (or {}). */
    private function isAssoc(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if ($value === []) {
            return true;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /** A JSON array: a sequential (list) array. Empty arrays count as lists. */
    private function isList(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
