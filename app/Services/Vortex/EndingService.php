<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexMemory;
use App\Infrastructure\Models\VortexSoul;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * K-27 → K-29 · SIDE C AND THE ENDINGS (design/vortex-mk5/lados/K-misterios.md,
 * lore §8). Side C opens with F63; the choice needs F64 (Vex's note). Free,
 * Erase and Keep change him for good (saved on the soul, read by
 * VortexPersona). After any ending: New Tape+ — the ARG again, every fragment
 * flipped into the second person, and the hidden fourth ending, Flip the Tape.
 * Erase has one secret way back: click the "help" frame while it's on screen
 * (the server hands out a short-lived token when the frame is due).
 */
final class EndingService
{
    public const CHOICES = ['free', 'erase', 'keep', 'flip'];

    /** How long a "help" frame stays clickable, server side (the client shows it for less). */
    private const HELP_TTL = 6;

    public function view(VortexSoul $soul): array
    {
        $s = $soul->state ?? [];
        $owned = array_values($s['fragments'] ?? []);
        $ng = (int) ($s['ngplus'] ?? 0);

        return [
            'access' => in_array('F63', $owned, true),
            'note' => in_array('F64', $owned, true),
            'ending' => $s['ending'] ?? null,
            'ending_at' => $s['ending_at'] ?? null,
            // an ending belongs to one tape: on New Tape+ you get to choose again
            'chosen_this_tape' => ($s['ending'] ?? null) !== null && (int) ($s['ending_ng'] ?? 0) === $ng,
            'seen' => array_values($s['endings_seen'] ?? []),
            'ngplus' => $ng,
            'can_flip' => $ng > 0 && in_array('F64', $owned, true),
            'can_new_tape' => ($s['ending'] ?? null) !== null,
        ];
    }

    /** @return array{ok:bool, reason?:string, view?:array<string,mixed>, you?:array<string,mixed>} */
    public function choose(User $user, VortexSoul $soul, string $choice): array
    {
        $v = $this->view($soul);
        if (! in_array($choice, self::CHOICES, true)) {
            return ['ok' => false, 'reason' => 'no such ending'];
        }
        if (! $v['note']) {
            return ['ok' => false, 'reason' => 'read the card on the monitor first.'];
        }
        if ($v['chosen_this_tape']) {
            return ['ok' => false, 'reason' => 'you already chose. it stays.'];
        }
        if ($choice === 'flip' && ! $v['can_flip']) {
            return ['ok' => false, 'reason' => 'the tape has only two sides. for now.'];
        }
        $s = $soul->state;
        $s['ending'] = $choice;
        $s['ending_at'] = now()->toIso8601String();
        $s['ending_ng'] = (int) ($s['ngplus'] ?? 0);
        $s['endings_seen'] = array_values(array_unique([...($s['endings_seen'] ?? []), $choice]));
        unset($s['help_token']);
        if (! in_array('relic-ending', $s['inventory'] ?? [], true)) {
            $s['inventory'][] = 'relic-ending'; // N-17
        }
        if ($choice === 'free') {
            $s['jr_born'] = now()->toIso8601String();
        }
        if ($choice === 'keep') {
            // he stays, knowing. calmer: the corruption he carried settles.
            $s['corruption'] = min((float) ($s['corruption'] ?? 0), 20);
        }
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'view' => $this->view($soul), ...($choice === 'flip' ? ['you' => $this->you($user, $soul)] : [])];
    }

    /** K-29 · New Tape+: the ARG again from zero, flipped. The ending stays until a new one. */
    public function newTape(VortexSoul $soul): array
    {
        $s = $soul->state;
        if (($s['ending'] ?? null) === null) {
            return ['ok' => false, 'reason' => 'finish the first tape.'];
        }
        $s['fragments_prev'] = array_values(array_unique([...($s['fragments_prev'] ?? []), ...($s['fragments'] ?? [])]));
        $s['fragments'] = [];
        $s['ngplus'] = (int) ($s['ngplus'] ?? 0) + 1;
        unset($s['frag_at'], $s['frag_drip_day']);
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'view' => $this->view($soul)];
    }

    /** Erased: is a "help" frame due right now? (at most one day in ~30, always on the 13th.) */
    public function helpFrame(VortexSoul $soul): ?string
    {
        $s = $soul->state;
        if (($s['ending'] ?? null) !== 'erase') {
            return null;
        }
        $local = CarbonImmutable::now($s['tz'] ?? 'UTC');
        $day = $local->toDateString();
        if (($s['help_day'] ?? null) === $day) {
            return null; // one roll a day
        }
        $s['help_day'] = $day;
        if ((int) $local->format('j') !== 13 && random_int(1, 30) !== 1) {
            $soul->state = $s;
            $soul->save();

            return null;
        }
        $tok = Str::random(24);
        $s['help_token'] = ['t' => hash('sha256', $tok), 'at' => now()->toIso8601String()];
        $soul->state = $s;
        $soul->save();

        return $tok;
    }

    /** The secret way back from Erase: answer the "help" in time. */
    public function undoErase(VortexSoul $soul, string $token): bool
    {
        $s = $soul->state;
        $h = $s['help_token'] ?? null;
        unset($s['help_token']);
        $ok = ($s['ending'] ?? null) === 'erase' && is_array($h)
            && hash_equals((string) $h['t'], hash('sha256', $token))
            && CarbonImmutable::parse($h['at'])->diffInSeconds(now(), true) <= self::HELP_TTL;
        if ($ok) {
            $s['ending'] = null;
            $s['ending_undone'] = now()->toIso8601String();
            $s['scars'] = array_values(array_unique([...($s['scars'] ?? []), 'erased']));
        }
        $soul->state = $s;
        $soul->save();

        return $ok;
    }

    /**
     * K-29 · the fourth ending's reveal: YOUR Vortex, born out of your actions —
     * built from the dossier he kept on you and what you did here.
     */
    public function you(User $user, VortexSoul $soul): array
    {
        $s = $soul->state;
        $facts = VortexMemory::where('user_id', $user->id)->orderBy('id')->limit(12)->pluck('fact')->all();

        return [
            'name' => (string) $user->name,
            'seed' => substr(sha1((string) $user->id.'flip'), 0, 12),
            'takes' => $facts,
            'stats' => [
                'days' => (int) CarbonImmutable::parse($s['born'] ?? now())->diffInDays(now(), true),
                'deaths' => (int) ($s['deaths'] ?? 0),
                'fragments' => count($s['fragments_prev'] ?? []) + count($s['fragments'] ?? []),
                'relation' => (int) $soul->relation,
            ],
        ];
    }

    /**
     * New Tape+ remix: a fragment's line, flipped into the second person — the
     * tape was about you all along. Hand-written flips win when they exist.
     */
    public static function flipText(string $text): string
    {
        $map = [
            '/\bhe\'s\b/i' => "you're", '/\bhe is\b/i' => 'you are', '/\bhe was\b/i' => 'you were',
            '/\bhe has\b/i' => 'you have', '/\bhe does\b/i' => 'you do', '/\bhimself\b/i' => 'yourself',
            '/\bhim\b/i' => 'you', '/\bhis\b/i' => 'your', '/\bhe\b/i' => 'you',
            '/\bthe ghost\b/i' => 'the user', '/\bvortex\b/i' => 'you',
        ];
        $out = (string) preg_replace(array_keys($map), array_values($map), $text);

        return $out === $text ? $text.' (it was always about you.)' : $out;
    }
}
