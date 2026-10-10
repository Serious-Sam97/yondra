<?php

declare(strict_types=1);

namespace App\Services\Vortex;

/**
 * The two text checks Vortex's chat needs on the server:
 * - {@see crisis()} spots a message that may mean real distress, so the persona
 *   prompt switches to the crisis instructions (he stays in character, but never
 *   mocks it and points to real help);
 * - {@see polite()} scrubs profanity out of a finished reply when the user chose
 *   the office-safe intensity (the prompt asks for it; this guarantees it).
 */
final class VortexSafety
{
    /** EN + PT-BR phrases that may signal self-harm, suicide or hopelessness. */
    private const CRISIS = [
        '/\b(kill|hurt|harm|cut)\s+my\s*self\b/iu',
        '/\bsuicid/iu',
        '/\bend\s+(it\s+all|my\s+life)\b/iu',
        '/\b(want|wanna|going)\s+to\s+die\b/iu',
        '/\bdon\'?t\s+want\s+to\s+(live|be\s+alive|exist)\b/iu',
        '/\bno\s+reason\s+to\s+live\b/iu',
        '/\bbetter\s+off\s+dead\b/iu',
        '/\bme\s+matar\b/iu',
        '/\bquero\s+(morrer|sumir|desaparecer)\b/iu',
        '/\bvou\s+me\s+matar\b/iu',
        '/\b(tirar|acabar\s+com)\s+(a\s+)?minha\s+(pr[óo]pria\s+)?vida\b/iu',
        '/\bn[ãa]o\s+(aguento|quero)\s+mais\s+(viver|existir)\b/iu',
        '/\bme\s+(cortar|machucar)\b/iu',
        '/\bsem\s+motivo\s+(pra|para)\s+viver\b/iu',
        '/\bsuic[íi]d/iu',
    ];

    /** Word → ridiculous substitute (polite intensity). */
    private const POLITE = [
        'motherfucker' => 'son of a backup',
        'fucking' => 'fudge-adjacent',
        'fucked' => 'demagnetized',
        'fuck' => 'fudge',
        'bullshit' => 'bull-static',
        'shit' => 'static',
        'bastard' => 'buffer',
        'bitch' => 'glitch',
        'asshole' => 'tape-head',
        'goddamn' => 'gosh-darn',
        'damn' => 'darn',
        'hell' => 'heck',
        'crap' => 'crud',
        'pissed' => 'peeved',
        'ass' => 'cassette',
        'porra' => 'poxa',
        'caralho' => 'caramba',
        'merda' => 'meleca',
        'puta' => 'pita',
        'foda' => 'fita',
        'cacete' => 'carambola',
    ];

    public static function crisis(string $text): bool
    {
        foreach (self::CRISIS as $re) {
            if (preg_match($re, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function polite(string $text): string
    {
        return (string) preg_replace_callback(
            '/\b('.implode('|', array_map('preg_quote', array_keys(self::POLITE))).')\b/iu',
            fn (array $m) => self::POLITE[mb_strtolower($m[1])] ?? $m[1],
            $text,
        );
    }
}
