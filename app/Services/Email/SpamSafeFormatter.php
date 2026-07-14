<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * Rewrites quote-email copy so Gmail's spam heuristics stop firing on it.
 *
 * A real client lost ~18 of every 20 quotes to spam: Gmail penalises currency
 * symbols next to prices ("R$1.500,00") and estimate keywords ("orçamento",
 * "quote"). This strips the symbols (keeping the number, appending the currency
 * word) and softens the trigger words into neutral synonyms. Pure and IO-free
 * so the rules are unit-testable; idempotent so a second pass is a no-op.
 */
class SpamSafeFormatter
{
    /** Currency symbol → spelled-out word. Longer symbols first so "R$"/"US$" win over "$". */
    private const SYMBOL_WORDS = [
        'R$' => 'reais',
        'US$' => 'dollars',
        '$' => 'dollars',
        '€' => 'euros',
        '£' => 'pounds',
    ];

    /** ISO currency → word, for the naturalized {{deal_value}} fallback. */
    private const CURRENCY_WORDS = [
        'BRL' => 'reais', 'USD' => 'dollars', 'EUR' => 'euros', 'GBP' => 'pounds',
    ];

    /**
     * Spam-trigger words → neutral synonym. Each entry lists every accent/spelling
     * variant that should collapse to the replacement. Matched whole-word,
     * case-insensitively; the match's capitalization is carried to the replacement.
     */
    private const TRIGGER_WORDS = [
        'proposta' => ['orçamento', 'orcamento', 'orçamentos', 'orcamentos'],
        'proposal' => ['quote', 'quotes', 'quotation', 'quotations'],
    ];

    /** Run every rule. Safe on already-clean text. */
    public function naturalize(string $text, string $currency = 'BRL'): string
    {
        return $this->softenTriggerWords($this->naturalizeMoney($text, $currency));
    }

    /**
     * "R$ 1.500,00" → "1.500,00 reais", "$1,500" → "1,500 dollars". Only touches a
     * symbol that actually sits against a number, so stray "$" in prose is left alone.
     */
    public function naturalizeMoney(string $text, string $currency = 'BRL'): string
    {
        // Alternation ordered longest-first (R$, US$ before $) so the greedy symbol wins.
        $symbols = implode('|', array_map(
            fn (string $s): string => preg_quote($s, '/'),
            array_keys(self::SYMBOL_WORDS),
        ));

        return preg_replace_callback(
            '/(?<sym>'.$symbols.')\s?(?<num>\d[\d.,]*)/u',
            function (array $m): string {
                $word = self::SYMBOL_WORDS[$m['sym']] ?? '';

                return rtrim($m['num']).' '.$word;
            },
            $text,
        ) ?? $text;
    }

    /** Replace estimate/quote trigger words with neutral synonyms, preserving case. */
    public function softenTriggerWords(string $text): string
    {
        foreach (self::TRIGGER_WORDS as $replacement => $variants) {
            $alt = implode('|', array_map(
                fn (string $v): string => preg_quote($v, '/'),
                $variants,
            ));

            $text = preg_replace_callback(
                '/\b(?:'.$alt.')\b/iu',
                fn (array $m): string => $this->matchCase($m[0], $replacement),
                $text,
            ) ?? $text;
        }

        return $text;
    }

    /** The spelled-out, symbol-free form of a money value (used for {{deal_value}}). */
    public function money(float $value, string $currency = 'BRL'): string
    {
        $word = self::CURRENCY_WORDS[$currency] ?? $currency;

        return number_format($value, 2).' '.$word;
    }

    /** Carry the sample's capitalization (Title / UPPER / lower) onto the replacement. */
    private function matchCase(string $sample, string $replacement): string
    {
        if (mb_strtoupper($sample) === $sample && mb_strlen($sample) > 1) {
            return mb_strtoupper($replacement);
        }
        if (mb_substr($sample, 0, 1) === mb_strtoupper(mb_substr($sample, 0, 1))) {
            return mb_strtoupper(mb_substr($replacement, 0, 1)).mb_substr($replacement, 1);
        }

        return $replacement;
    }
}
