<?php

declare(strict_types=1);

namespace App\Services\Vortex;

/**
 * The red lines for anything a person writes that he will later say or show:
 * dedications, taught lines, trick lines, plaques. Profanity is allowed (he is
 * Unhinged); slurs, sexual content, self-harm encouragement, threats, links
 * and @mentions are not. Cheap and deterministic on purpose: it runs on save.
 */
final class RedLines
{
    private const BLOCK = '/\b(nazi|retard|fag|faggot|nigg|tranny|kike|spic|chink|viado|bicha|traveco|macaco|crioulo|puta|vagabunda|piranha|estupr|rape|porn|porno|nude|nudes|sexo|sex\b|kill yourself|kys|se mata|se matar|suicid|self.?harm|i\'?ll kill|vou te matar)\w*/iu';

    public static function ok(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) > 200) {
            return false;
        }
        if (preg_match(self::BLOCK, $t) === 1) {
            return false;
        }
        // no links, no pinging real people through him
        if (preg_match('#(https?://|www\.|\.[a-z]{2,4}/|@[\w.-]{2,})#iu', $t) === 1) {
            return false;
        }

        return true;
    }

    /** Collapse whitespace and strip control characters before storing. */
    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $text)));
    }
}
