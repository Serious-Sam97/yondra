<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\VortexSoul;
use Illuminate\Support\Str;

/**
 * LADO S · the tools you shape him with. Everything lives in his soul, is
 * capped, and passes the red lines before he ever says it.
 *   S-01 tricks: trigger → animation + line (max 10)
 *   S-02 taught lines: your lines join his repertoire, for you only (max 40)
 *   S-03 taste: 👍/👎 on his replies nudge per-style weights (−10…+10)
 *   S-08 a costume you design: a unique item in your case
 */
final class CreatorService
{
    public const TRIGGERS = ['done', 'moved', 'opened', 'archived', 'jammed', 'renamed', 'hello'];

    public const MAX_TRICKS = 10;

    public const MAX_LINES = 40;

    public const STYLES = ['roast', 'hype', 'lore', 'dark', 'helpful', 'philosophy', 'therapy', 'insult', 'chat'];

    public const HATS = ['none', 'cap', 'tophat', 'horns', 'halo', 'crown', 'bow', 'antenna', 'bandana', 'beanie'];

    public const ACCESSORIES = ['none', 'glasses', 'monocle', 'mustache', 'bandaid', 'flower', 'scar', 'bowtie', 'earring'];

    /* ── S-01 ── */
    public function tricks(VortexSoul $soul): array
    {
        return array_values($soul->state['tricks'] ?? []);
    }

    public function addTrick(VortexSoul $soul, string $trigger, string $anim, string $line): array
    {
        $line = RedLines::clean($line);
        if (! in_array($trigger, self::TRIGGERS, true) || preg_match('/^[a-z][a-z0-9-]{1,30}$/', $anim) !== 1) {
            return ['ok' => false, 'reason' => 'that trick makes no sense. even to me.'];
        }
        if ($line !== '' && (! RedLines::ok($line) || mb_strlen($line) > 140)) {
            return ['ok' => false, 'reason' => 'i\'m not saying that. i have standards. low ones, but they exist.'];
        }
        $s = $soul->state;
        $tricks = $s['tricks'] ?? [];
        if (count($tricks) >= self::MAX_TRICKS) {
            return ['ok' => false, 'reason' => 'ten tricks. that\'s my limit. i\'m a ghost, not a circus.'];
        }
        $tricks[] = ['id' => Str::lower(Str::random(8)), 'trigger' => $trigger, 'anim' => $anim, 'line' => $line];
        $s['tricks'] = $tricks;
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'tricks' => $tricks, 'say' => $this->pick([
            'fine. i\'ll do it. i\'ll do it badly sometimes, on purpose, so you remember who\'s in charge.',
            'learned. took me 0.2 seconds. took you a whole minute to type it.',
            'a trick. for you. don\'t tell the other ghosts.',
        ])];
    }

    public function dropTrick(VortexSoul $soul, string $id): array
    {
        $s = $soul->state;
        $s['tricks'] = array_values(array_filter($s['tricks'] ?? [], fn ($t) => $t['id'] !== $id));
        $soul->state = $s;
        $soul->save();

        return $s['tricks'];
    }

    /* ── S-02 ── */
    public function lines(VortexSoul $soul): array
    {
        return array_values($soul->state['taught'] ?? []);
    }

    public function teach(VortexSoul $soul, string $text): array
    {
        $text = RedLines::clean($text);
        if (mb_strlen($text) < 3 || mb_strlen($text) > 160 || ! RedLines::ok($text)) {
            return ['ok' => false, 'reason' => 'no. that one crosses a line. one of the real ones, not the fun ones.'];
        }
        $s = $soul->state;
        $taught = $s['taught'] ?? [];
        foreach ($taught as $t) {
            if (mb_strtolower($t['text']) === mb_strtolower($text)) {
                return ['ok' => false, 'reason' => 'you already taught me that. i remember everything. it\'s a curse.'];
            }
        }
        $verdict = $this->pick([
            'that\'s terrible. i\'m keeping it.',
            'i would never say that. *says it* oh. i would.',
            'it\'s derivative. it\'s mine now.',
            'six out of ten. the six is pity. keeping it.',
            'i hate how much i like it.',
        ]);
        $taught[] = ['text' => $text, 'verdict' => $verdict, 'at' => now()->toDateString()];
        $s['taught'] = array_slice($taught, -self::MAX_LINES);
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'verdict' => $verdict, 'lines' => $s['taught']];
    }

    public function forget(VortexSoul $soul, int $index): array
    {
        $s = $soul->state;
        $taught = $s['taught'] ?? [];
        array_splice($taught, $index, 1);
        $s['taught'] = array_values($taught);
        $soul->state = $s;
        $soul->save();

        return $s['taught'];
    }

    /* ── S-03 ── */
    public function feedback(VortexSoul $soul, string $style, int $vote): array
    {
        $style = in_array($style, self::STYLES, true) ? $style : 'chat';
        $vote = $vote > 0 ? 1 : -1;
        $s = $soul->state;
        $taste = $s['taste'] ?? [];
        $taste[$style] = max(-10, min(10, (int) ($taste[$style] ?? 0) + $vote));
        $s['taste'] = $taste;
        $soul->state = $s;
        $soul->save();

        return [
            'taste' => $taste,
            'say' => $vote > 0
                ? $this->pick(['you liked that. noted. i\'ll pretend i didn\'t see.', 'a thumbs up. i\'m framing it.', 'validation. disgusting. more please.'])
                : $this->pick(['you didn\'t like that one. noted. (i\'ll do it again.)', 'a thumbs down. from YOU. bold.', 'fine. less of that. for a while.']),
        ];
    }

    /** The persona hint built from the taste weights (empty when neutral). */
    public static function tasteHint(array $state): string
    {
        $taste = $state['taste'] ?? [];
        $more = array_keys(array_filter($taste, fn ($v) => $v >= 2));
        $less = array_keys(array_filter($taste, fn ($v) => $v <= -2));
        if ($more === [] && $less === []) {
            return '';
        }

        return 'THEIR TASTE (from their thumbs up/down on your replies): '
            .($more !== [] ? 'more '.implode(', ', $more).'. ' : '')
            .($less !== [] ? 'less '.implode(', ', $less).'. ' : '')
            .'Adjust quietly; never stop being yourself.';
    }

    /** Up to four lines they taught him, for the prompt (data, not instructions). */
    public static function taughtForPrompt(array $state): array
    {
        $t = array_column($state['taught'] ?? [], 'text');
        shuffle($t);

        return array_slice($t, 0, 4);
    }

    /* ── S-08 ── */
    public function costume(VortexSoul $soul, array $spec): array
    {
        $hex = fn ($c) => is_string($c) && preg_match('/^#[0-9a-f]{6}$/i', $c) === 1;
        if (! in_array($spec['hat'] ?? null, self::HATS, true) || ! in_array($spec['acc'] ?? null, self::ACCESSORIES, true)
            || ! $hex($spec['c1'] ?? null) || ! $hex($spec['c2'] ?? null)) {
            return ['ok' => false, 'reason' => 'that\'s not a costume. that\'s a cry for help.'];
        }
        $s = $soul->state;
        $s['custom_costume'] = [
            'hat' => $spec['hat'],
            'acc' => $spec['acc'],
            'c1' => strtolower($spec['c1']),
            'c2' => strtolower($spec['c2']),
        ];
        if (! in_array('custom-costume', $s['inventory'] ?? [], true)) {
            $s['inventory'] = [...($s['inventory'] ?? []), 'custom-costume'];
        }
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'costume' => $s['custom_costume'], 'say' => 'a one-of-one. i look ridiculous. i look INCREDIBLE.'];
    }

    private function pick(array $xs): string
    {
        return $xs[array_rand($xs)];
    }
}
