<?php

declare(strict_types=1);

namespace App\Services\Intake;

/**
 * Turns a raw form-submission payload (JotForm today, any flat form tomorrow)
 * into the fields Yondra needs to open an intake card. Pure and IO-free so the
 * mapping heuristics are unit-testable in isolation.
 *
 * JotForm posts a mix of shapes:
 *  - flat POST fields (formID, submissionID, pretty, …)
 *  - a `rawRequest` JSON string keyed by "q{N}_{fieldName}", where a value may be
 *    a scalar, a name object {first,last}, an address object, or an array of
 *    uploaded-file URLs.
 * We decode rawRequest, flatten everything to (label => text) pairs, then map by
 * label keyword. Whatever we don't map is appended to the description verbatim so
 * a submission never silently loses information.
 */
class IntakeSubmissionParser
{
    /** Field labels (matched as substrings, case-insensitive) that name the person. */
    private const NAME_KEYS = ['full name', 'fullname', 'your name', 'contact name', 'name'];

    private const EMAIL_KEYS = ['email', 'e-mail', 'mail'];

    private const PHONE_KEYS = ['phone', 'telephone', 'tel', 'mobile', 'cell', 'whatsapp'];

    /** Labels that make a good card title. */
    private const SUBJECT_KEYS = ['subject', 'title', 'project', 'service', 'request', 'topic', 'summary'];

    /** Labels whose value is free-text describing the request. */
    private const MESSAGE_KEYS = ['message', 'description', 'details', 'notes', 'note', 'comment', 'body', 'requirements', 'brief', 'quote'];

    /**
     * Housekeeping fields JotForm sends that must never surface on the card.
     * Compared against the raw key normalized to alphanumerics (so "submissionID",
     * "submission_id" and "rawRequest" all collapse to a listed form).
     */
    private const IGNORED_KEYS = ['formid', 'submissionid', 'pretty', 'rawrequest', 'webhookurl', 'eventid', 'ip', 'formtitle', 'tempupload'];

    /** Card-attribute targets a mapping rule may point a form field at. */
    private const MAP_TARGETS = [
        'title', 'description', 'value', 'tags', 'priority', 'story_points',
        'due_date', 'contact_name', 'contact_email', 'contact_phone', 'ignore',
    ];

    /**
     * @param  array<string,mixed>  $payload  the merged request input (POST fields + query)
     * @param  array<int,array{source:string,target:string}>  $map  board-configured
     *                                                              field → attribute overrides (YON-50). Empty = pure heuristics.
     * @return array{name:string, description:string, value:?float, tags:array<int,string>,
     *         priority:?string, story_points:?int, due_date:?string,
     *         contact:array{name:?string,email:?string,phone:?string}, files:array<int,string>}
     */
    public function parse(array $payload, array $map = []): array
    {
        $submissionId = (string) ($payload['submissionID'] ?? $payload['submission_id'] ?? '');

        // Decode rawRequest (the authoritative answer set) and merge it over the
        // flat fields; its "q{N}_" keys carry the real question labels.
        $answers = $payload;
        if (isset($payload['rawRequest']) && is_string($payload['rawRequest'])) {
            $decoded = json_decode($payload['rawRequest'], true);
            if (is_array($decoded)) {
                $answers = array_merge($answers, $decoded);
            }
        }

        /** @var array<int,array{label:string,value:string}> $fields */
        $fields = [];
        $files = [];

        foreach ($answers as $key => $value) {
            // Compare the raw key (normalized to alphanumerics) against the ignore
            // list BEFORE cleaning — cleaning inserts spaces that would break the match.
            $normalized = preg_replace('/[^a-z0-9]/', '', strtolower((string) $key)) ?? '';
            if (in_array($normalized, self::IGNORED_KEYS, true)) {
                continue;
            }
            $label = $this->cleanLabel((string) $key);
            if ($label === '') {
                continue;
            }

            // Pull any uploaded-file URLs out of this answer, scalar or nested.
            foreach ($this->extractUrls($value) as $url) {
                $files[] = $url;
            }

            $text = $this->stringify($value);
            if ($text !== '') {
                $fields[] = ['label' => $label, 'value' => $text];
            }
        }

        $used = [];

        // Explicit board mapping wins and consumes its fields first; heuristics then
        // fill any target the manager didn't map, so a partial map still benefits.
        $mapped = $this->applyMap($fields, $map, $used);

        $contactName = $mapped['contact_name'] ?: $this->firstMatch($fields, self::NAME_KEYS, $used);
        $email = $mapped['contact_email'] ?: $this->firstMatch($fields, self::EMAIL_KEYS, $used, fn ($v) => $this->looksLikeEmail($v));
        $phone = $mapped['contact_phone'] ?: $this->firstMatch($fields, self::PHONE_KEYS, $used);
        $subject = $this->firstMatch($fields, self::SUBJECT_KEYS, $used);
        $message = $mapped['description'] ?: $this->firstMatch($fields, self::MESSAGE_KEYS, $used);

        // Title priority: an explicit mapping, else a subject-ish field, else the
        // message's first line, else the person's name, else a submission reference.
        $name = $mapped['title']
            ?: $subject
            ?: $this->firstLine($message)
            ?: ($contactName ? "Intake — {$contactName}" : '')
            ?: ($submissionId !== '' ? "Intake submission #{$submissionId}" : 'Form submission');
        $name = mb_substr($name, 0, 255);

        $description = $this->buildDescription($message, $fields, $used);

        return [
            'name' => $name,
            'description' => $description,
            'value' => $mapped['value'],
            'tags' => $mapped['tags'],
            'priority' => $mapped['priority'],
            'story_points' => $mapped['story_points'],
            'due_date' => $mapped['due_date'],
            'contact' => [
                'name' => $contactName ?: null,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
            ],
            'files' => array_values(array_unique($files)),
        ];
    }

    /**
     * Resolve the board's field-mapping rules against the flattened fields. Each rule
     * consumes the first still-unused field whose label contains its (lowercased)
     * source substring; the value is coerced to the target attribute's type.
     *
     * @param  array<int,array{label:string,value:string}>  $fields
     * @param  array<int,array{source:string,target:string}>  $map
     * @param  array<int,int>  $used
     * @return array{title:?string, description:?string, value:?float, tags:array<int,string>,
     *         priority:?string, story_points:?int, due_date:?string,
     *         contact_name:?string, contact_email:?string, contact_phone:?string}
     */
    private function applyMap(array $fields, array $map, array &$used): array
    {
        $out = [
            'title' => null, 'description' => null, 'value' => null, 'tags' => [],
            'priority' => null, 'story_points' => null, 'due_date' => null,
            'contact_name' => null, 'contact_email' => null, 'contact_phone' => null,
        ];

        foreach ($map as $rule) {
            $source = strtolower(trim((string) ($rule['source'] ?? '')));
            $target = (string) ($rule['target'] ?? '');
            if ($source === '' || ! in_array($target, self::MAP_TARGETS, true)) {
                continue;
            }

            $value = $this->matchField($fields, $source, $used);
            if ($value === null) {
                continue;
            }

            match ($target) {
                'title' => $out['title'] ??= mb_substr($value, 0, 255),
                'description' => $out['description'] = trim(($out['description'] ? $out['description']."\n" : '').$value),
                'value' => $out['value'] ??= $this->parseMoney($value),
                'tags' => array_push($out['tags'], ...$this->splitTags($value)),
                'priority' => $out['priority'] ??= $this->parsePriority($value),
                'story_points' => $out['story_points'] ??= $this->parseStoryPoints($value),
                'due_date' => $out['due_date'] ??= $this->parseDueDate($value),
                'contact_name' => $out['contact_name'] ??= $value,
                'contact_email' => $out['contact_email'] ??= $value,
                'contact_phone' => $out['contact_phone'] ??= $value,
                'ignore' => null, // consumed above; kept off the card entirely
            };
        }

        $out['tags'] = array_values(array_unique($out['tags']));

        return $out;
    }

    /**
     * First still-unused field whose label contains $source (case-insensitive),
     * marking it used. Null when nothing matches.
     *
     * @param  array<int,array{label:string,value:string}>  $fields
     * @param  array<int,int>  $used
     */
    private function matchField(array $fields, string $source, array &$used): ?string
    {
        foreach ($fields as $i => $field) {
            if (in_array($i, $used, true)) {
                continue;
            }
            if (str_contains(strtolower($field['label']), $source)) {
                $used[] = $i;

                return $field['value'];
            }
        }

        return null;
    }

    /**
     * Description = the free-text message (if any) followed by a "Submission details"
     * block listing every field we didn't already consume, so nothing is lost.
     *
     * @param  array<int,array{label:string,value:string}>  $fields
     * @param  array<int,int>  $used  indices already mapped to a dedicated field
     */
    private function buildDescription(string $message, array $fields, array $used): string
    {
        $lines = [];
        foreach ($fields as $i => $field) {
            if (in_array($i, $used, true)) {
                continue;
            }
            $lines[] = "**{$field['label']}:** {$field['value']}";
        }

        $parts = [];
        if ($message !== '') {
            $parts[] = $message;
        }
        if ($lines !== []) {
            $parts[] = "**Submission details**\n".implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }

    /**
     * First field whose label contains one of $keys (and passes the optional value
     * test). Records the chosen index in $used so it isn't repeated in the details.
     *
     * @param  array<int,array{label:string,value:string}>  $fields
     * @param  array<int,string>  $keys
     * @param  array<int,int>  $used
     */
    private function firstMatch(array $fields, array $keys, array &$used, ?callable $valueTest = null): string
    {
        foreach ($keys as $key) {
            foreach ($fields as $i => $field) {
                if (in_array($i, $used, true)) {
                    continue;
                }
                if (! str_contains(strtolower($field['label']), $key)) {
                    continue;
                }
                if ($valueTest && ! $valueTest($field['value'])) {
                    continue;
                }
                $used[] = $i;

                return $field['value'];
            }
        }

        return '';
    }

    /** Strip JotForm's "q{N}_" prefix and turn snake/camel labels into words. */
    private function cleanLabel(string $key): string
    {
        $key = preg_replace('/^q\d+_/', '', $key) ?? $key;
        $key = str_replace(['_', '-'], ' ', $key);
        $key = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $key) ?? $key; // camelCase → words

        return trim(ucwords(strtolower($key)));
    }

    /** Flatten a scalar / name-object / address-object / list into readable text. */
    private function stringify(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            $s = trim((string) $value);

            // A bare uploaded-file URL is captured separately; don't echo it as text.
            return $this->isFileUrl($s) ? '' : $s;
        }

        if (is_array($value)) {
            // Name object {first, last, prefix, …} — keep human order.
            $ordered = [];
            foreach (['prefix', 'first', 'middle', 'last', 'suffix'] as $part) {
                if (! empty($value[$part])) {
                    $ordered[] = $value[$part];
                }
            }
            if ($ordered !== []) {
                return trim(implode(' ', $ordered));
            }

            // Generic map/list: join non-empty scalar leaves, skipping file URLs.
            $parts = [];
            array_walk_recursive($value, function ($leaf) use (&$parts) {
                $s = is_scalar($leaf) ? trim((string) $leaf) : '';
                if ($s !== '' && ! $this->isFileUrl($s)) {
                    $parts[] = $s;
                }
            });

            return implode(', ', $parts);
        }

        return '';
    }

    /**
     * Collect uploaded-file URLs from an answer of any shape.
     *
     * @return array<int,string>
     */
    private function extractUrls(mixed $value): array
    {
        $urls = [];
        if (is_string($value)) {
            if ($this->isFileUrl(trim($value))) {
                $urls[] = trim($value);
            }
        } elseif (is_array($value)) {
            array_walk_recursive($value, function ($leaf) use (&$urls) {
                if (is_string($leaf) && $this->isFileUrl(trim($leaf))) {
                    $urls[] = trim($leaf);
                }
            });
        }

        return $urls;
    }

    private function isFileUrl(string $value): bool
    {
        if (! preg_match('#^https?://#i', $value)) {
            return false;
        }
        $path = (string) parse_url($value, PHP_URL_PATH);

        // JotForm upload URLs (…/uploads/…) or any URL ending in a document/archive
        // extension count as attachments.
        return str_contains($value, '/uploads/')
            || (bool) preg_match('/\.(pdf|docx?|xlsx?|pptx?|txt|csv|md|rtf|odt|ods|odp|zip|png|jpe?g|gif|webp)$/i', $path);
    }

    private function looksLikeEmail(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    private function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: '');

        return mb_strlen($line) > 120 ? '' : $line;
    }

    /**
     * Best-effort money parse. Handles currency symbols, k/m suffixes, and both
     * BR ("1.500,00") and US ("1,500.00") separator conventions. Takes the first
     * numeric token (so a "20k–30k" range yields the low end). Null if no number.
     */
    private function parseMoney(string $value): ?float
    {
        if (! preg_match('/(\d[\d.,]*)\s*([kKmM])?/u', $value, $m)) {
            return null;
        }
        $num = $m[1];
        $suffix = strtolower($m[2] ?? '');
        $hasComma = str_contains($num, ',');
        $hasDot = str_contains($num, '.');

        if ($hasComma && $hasDot) {
            // Rightmost separator is the decimal point; the other groups thousands.
            if (strrpos($num, ',') > strrpos($num, '.')) {
                $num = str_replace(',', '.', str_replace('.', '', $num)); // BR
            } else {
                $num = str_replace(',', '', $num); // US
            }
        } elseif ($hasComma) {
            $decimals = strlen(substr($num, (int) strrpos($num, ',') + 1));
            $num = $decimals === 2 ? str_replace(',', '.', $num) : str_replace(',', '', $num);
        } elseif ($hasDot) {
            $decimals = strlen(substr($num, (int) strrpos($num, '.') + 1));
            if (substr_count($num, '.') > 1 || $decimals === 3) {
                $num = str_replace('.', '', $num); // "1.500" style thousands
            }
        }

        $result = (float) $num;
        $result *= match ($suffix) {
            'k' => 1_000,
            'm' => 1_000_000,
            default => 1,
        };

        return $result;
    }

    /** Map free text onto the card's low/medium/high priority, or null if unclear. */
    private function parsePriority(string $value): ?string
    {
        $v = mb_strtolower($value);

        return match (true) {
            (bool) preg_match('/high|urgent|urgente|alta/u', $v) => 'high',
            (bool) preg_match('/low|baixa/u', $v) => 'low',
            (bool) preg_match('/medium|normal|m[eé]dia/u', $v) => 'medium',
            default => null,
        };
    }

    private function parseStoryPoints(string $value): ?int
    {
        return preg_match('/\d+/', $value, $m) ? (int) $m[0] : null;
    }

    /** Parse a human date into Y-m-d; null when unrecognizable. */
    private function parseDueDate(string $value): ?string
    {
        $ts = strtotime(trim($value));

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    /**
     * Split a tag field ("web, design; seo") into individual tag names.
     *
     * @return array<int,string>
     */
    private function splitTags(string $value): array
    {
        $parts = preg_split('/[,;\/|]+/', $value) ?: [];
        $tags = array_map(fn (string $t): string => trim($t), $parts);

        return array_values(array_filter($tags, fn (string $t): bool => $t !== ''));
    }
}
