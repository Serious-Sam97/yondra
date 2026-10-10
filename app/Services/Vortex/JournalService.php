<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexJournal;
use App\Infrastructure\Models\VortexSoul;
use App\Services\Ai\AiDriver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Things Vortex writes (C-12 diary, F-14 letters). One LLM call per entry,
 * cached forever in vortex_journal — never more than one diary entry per day.
 * The diary is honest in a way he never is to your face, and some lines are in
 * someone else's handwriting (marked «like this»): Vex, writing through him.
 */
final class JournalService
{
    public function __construct(private readonly AiDriver $ai) {}

    /** Yesterday's diary entry, generated on first read. */
    public function diaryFor(User $user, VortexSoul $soul): ?VortexJournal
    {
        $tz = $soul->state['tz'] ?? 'UTC';
        $day = CarbonImmutable::now($tz)->subDay()->toDateString();
        $existing = VortexJournal::where('user_id', $user->id)->where('kind', 'diary')->whereDate('day', $day)->first();
        if ($existing || ! $this->ai->isAvailable()) {
            return $existing;
        }

        $s = $soul->state;
        $needs = collect($s['needs'] ?? [])->map(fn ($v, $k) => "{$k} ".round($v))->implode(', ');
        $away = collect($s['away'] ?? [])->pluck('text')->take(4)->implode(' / ');
        $first = strtok((string) $user->name, ' ') ?: 'them';
        $system = 'You are Vortex, the ghost in the tape machine of a 1980s hi-fi project app, writing in your PRIVATE diary. '
            .'Nobody will ever read this (you think). Be honest in a way you never are out loud: you like the user more than you admit, '
            .'you are afraid of "her" (the thing that rewinds tapes), you miss someone you can\'t remember. '
            .'Lowercase, dry, funny and sad, 4 to 7 short lines, first person, about yesterday. '
            .'Exactly ONE line must be in someone else\'s handwriting: wrap it in «guillemets» — that line is calm, human, '
            .'old-fashioned, and mentions tea, a garage, or the number 0313. No markdown, no title, no date.';
        $facts = "the user is {$first}. relationship: ".(int) $soul->relation.'/100. '
            ."your needs yesterday: {$needs}. corruption ".round($s['corruption'] ?? 0).'. '
            .($away !== '' ? "things you did while they were away: {$away}. " : '')
            .'traits: '.implode(', ', $s['traits'] ?? []).'.';

        try {
            AiBudget::spend($user->id, 'diary');
            $text = trim($this->ai->complete($system, [['role' => 'user', 'content' => $facts]], 400));
        } catch (\Throwable $e) {
            Log::warning('Vortex diary failed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return null;
        }
        if ($text === '') {
            return null;
        }

        return VortexJournal::create([
            'user_id' => $user->id,
            'kind' => 'diary',
            'day' => $day,
            'body' => mb_substr(mb_strtolower($text), 0, 2000),
        ]);
    }

    /** F-14 · is this month's letter due (a seeded day of the month per user)? */
    public function letterDue(User $user, VortexSoul $soul): bool
    {
        $now = CarbonImmutable::now($soul->state['tz'] ?? 'UTC');
        $day = (crc32('letter:'.$user->id) % 25) + 1;
        if ((int) $now->format('j') < $day) {
            return false;
        }

        return ! VortexJournal::where('user_id', $user->id)->where('kind', 'letter')
            ->whereBetween('day', [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()])->exists();
    }

    /** F-14 · write this month's letter (typewritten, with a handwritten P.S.). */
    public function writeLetter(User $user, VortexSoul $soul): ?VortexJournal
    {
        if (! $this->letterDue($user, $soul) || ! $this->ai->isAvailable()) {
            return null;
        }
        $s = $soul->state;
        $first = strtok((string) $user->name, ' ') ?: 'you';
        $rel = (int) $soul->relation;
        $system = 'You are Vortex, the ghost in the tape machine of a 1980s hi-fi project app, writing a short LETTER to the user. '
            .'Lowercase, dry, in your voice. Relationship '.$rel.'/100: low = a complaint dressed as a letter, high = gratitude disguised as insults. '
            .'4 to 6 short lines, then a last line starting with "p.s." that is a little ominous (the pencil, "her", watch the corners). '
            .'No markdown, no subject line.';
        $facts = "to: {$first}. their recent habits: ".json_encode($s['counters'] ?? [])
            .'. what you did lately: '.collect($s['away'] ?? [])->pluck('text')->take(3)->implode(' / ');
        try {
            AiBudget::spend($user->id, 'letters');
            $text = trim($this->ai->complete($system, [['role' => 'user', 'content' => $facts]], 400));
        } catch (\Throwable $e) {
            Log::warning('Vortex letter failed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $text === '' ? null : VortexJournal::create([
            'user_id' => $user->id,
            'kind' => 'letter',
            'day' => CarbonImmutable::now($s['tz'] ?? 'UTC')->toDateString(),
            'body' => mb_substr(mb_strtolower($text), 0, 1500),
        ]);
    }
}
