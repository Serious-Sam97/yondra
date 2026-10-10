<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexMemory;
use App\Infrastructure\Models\VortexSoul;
use App\Infrastructure\Models\VortexWorldState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * C · THE SOUL (design/vortex-mk5/lados/C-alma.md). Vortex's state lives here,
 * on the server, so he is the same ghost on every device and keeps living
 * while you're away:
 *  - needs that decay with time (hunger, boredom, sanity, loneliness, ego, energy);
 *  - events the client reports (fed, chat, ignored…) with daily caps — the
 *    client sends EVENTS, never state, so nobody can DevTools their way to 100;
 *  - a mood with a CAUSE he can explain;
 *  - emergent traits from your history together;
 *  - stable per-user likes, an agenda (his "GET OUT" project) and a log of what
 *    he did while you were gone, produced by the hourly life tick.
 */
final class SoulService
{
    public const VERSION = 1;

    public const NEEDS = ['hunger', 'boredom', 'sanity', 'loneliness', 'ego', 'energy'];

    /**
     * Client events and their effects. `cap` = max applications per local day.
     * Keys inside `n` are need deltas; `rel` relation; `cor` corruption; `c` counter.
     */
    public const EVENTS = [
        'visit' => ['cap' => 999],
        'chat' => ['n' => ['loneliness' => -15, 'boredom' => -10], 'rel' => 1, 'c' => 'chats', 'cap' => 6],
        'compliment' => ['n' => ['ego' => 6], 'rel' => 1, 'c' => 'kind', 'cap' => 4],
        'fed' => ['n' => ['hunger' => -30], 'c' => 'fed', 'cap' => 10],
        'ate' => ['n' => ['hunger' => -18], 'c' => 'fed', 'cap' => 10],
        'card_done' => ['n' => ['boredom' => -4], 'cor' => -1, 'c' => 'done', 'cap' => 25],
        'ignored' => ['n' => ['ego' => -2], 'rel' => -1, 'c' => 'ignored', 'cap' => 5],
        'woke' => ['n' => ['energy' => -10], 'rel' => -2, 'cap' => 3],
        'caught_lie' => ['n' => ['ego' => -5], 'rel' => 2, 'c' => 'caught', 'cap' => 3],
        'twin' => ['rel' => -3, 'c' => 'twin', 'cap' => 3],
        'game' => ['n' => ['boredom' => -20, 'loneliness' => -6], 'rel' => 1, 'c' => 'games', 'cap' => 8],
        'lore' => ['n' => ['sanity' => -3], 'cor' => 2, 'c' => 'lore', 'cap' => 10],
        'night' => ['c' => 'night', 'cor' => 1, 'cap' => 1],
        'care' => ['n' => ['loneliness' => -5], 'cap' => 30],
        'apology' => ['rel' => 1, 'cap' => 3],
        'debt_paid' => ['rel' => 2, 'n' => ['ego' => 3], 'cap' => 5],
        'gift' => ['rel' => 2, 'n' => ['ego' => 4], 'c' => 'kind', 'cap' => 3],
        'rude' => ['n' => ['ego' => -1], 'rel' => 1, 'cap' => 3], // finally, some spine
        'sweet' => ['rel' => 0, 'c' => 'kind', 'cap' => 5],
        'scrolled' => ['n' => ['sanity' => -1], 'c' => 'scrolled', 'cap' => 5],
        'reopened' => ['c' => 'reopen', 'cap' => 3], // D-02 · you keep reopening the same card
        'dark_seen' => ['n' => ['sanity' => -4], 'cor' => 1, 'cap' => 6],
        'purify' => ['cap' => 1],
        'returned' => ['cap' => 1], // F-16 · re-enabled after being switched off
        'bond_seen' => ['cap' => 1], // F-25 · the bond scene played
        'promise_kept' => ['rel' => 2, 'cap' => 3], // F-20
        'promise_broken' => ['rel' => -2, 'n' => ['ego' => -2], 'cap' => 3],
        'bet' => ['n' => ['boredom' => -6], 'cap' => 5], // F-06
        'dare' => ['n' => ['boredom' => -8], 'rel' => 1, 'cap' => 4], // F-05
        'help' => ['rel' => 2, 'n' => ['loneliness' => -8], 'cap' => 3], // F-22
        // H · the dark side
        'grabbed' => ['n' => ['sanity' => -20], 'cor' => 6, 'cap' => 3], // H-04 the hand got him
        'hid' => ['n' => ['sanity' => 4], 'rel' => 2, 'cap' => 5], // H-04 you hid him in time
        'die' => ['cap' => 1], // H-15
        'invoke_rewinder' => ['cor' => 5, 'cap' => 3], // H-17 DNIWER
        'exorcised' => ['n' => ['sanity' => 6], 'cor' => -4, 'cap' => 5], // H-05
        'rescue' => ['rel' => 6, 'cap' => 1], // H-11 back from behind the mirror
        'halloween' => ['cap' => 1], // H-23
        'nightmare_calmed' => ['n' => ['sanity' => 8], 'rel' => 1, 'cap' => 3], // H-25
    ];

    private const COLORS = ['amber', 'rust', 'olive', 'teal', 'plum', 'tan', 'mustard', 'slate'];

    private const FUNNY = ['synergy', 'leverage', 'quick', 'sync', 'urgent', 'v2', 'final', 'misc', 'alignment', 'asap', 'circle back', 'tbd'];

    /** His agenda: the cards on his ghost board "GET OUT" (C-10 / C-18). */
    private const AGENDA = [
        ['id' => 'edge', 'title' => 'find the edge of the tape', 'col' => 'doing'],
        ['id' => 'pencils', 'title' => 'stop eating pencils', 'col' => 'todo'],
        ['id' => 'radio', 'title' => 'figure out who the radio lady is', 'col' => 'blocked'],
        ['id' => 'twin', 'title' => 'get bigger than the twin', 'col' => 'todo'],
        ['id' => 'metronome', 'title' => 'outsmart the metronome', 'col' => 'doing'],
        ['id' => 'escape', 'title' => 'escape', 'col' => 'backlog'],
    ];

    /** What he did while you were away, by his loudest need. */
    private const AWAY = [
        'hunger' => [
            'ate the oldest card in your backlog. not literally. ok, a little.',
            'stared at an overdue card for {h} hours. it stared back. delicious.',
            'licked the due dates. they taste like copper and guilt.',
        ],
        'boredom' => [
            'rearranged nothing. twice.',
            'counted the pixels in your sidebar. you have a lot of sidebar.',
            'played rock paper scissors against myself. lost.',
            'invented three things. destroyed two. the third one hums.',
        ],
        'loneliness' => [
            'talked to the void for {h} hours. it was a better listener. barely.',
            'sat in your empty board and waited. i was not sad. shut up.',
            'wrote your name on the inside of the tape window. then erased it. then wrote it again.',
        ],
        'sanity' => [
            'heard rewinding behind the footer. checked. nothing. checked again. nothing. checked again.',
            'the moth asked about you. i told him you died.',
            'the radio played a song for someone called "m". i turned it off. it turned back on.',
        ],
        'energy' => [
            'slept {h} hours on top of your done column. it\'s warm there.',
            'napped in the basement. dreamed about a garage. weird. i\'ve never been in a garage.',
        ],
        'ego' => [
            'practised my acceptance speech. for what? exactly.',
            'reviewed my own code. flawless. 2,814 lines of perfection.',
        ],
    ];

    public function for(User $user): VortexSoul
    {
        return VortexSoul::firstOrCreate(
            ['user_id' => $user->id],
            ['state' => $this->defaults($user), 'last_tick_at' => now(), 'last_seen_at' => now()],
        );
    }

    /** A fresh soul: needs at comfortable levels, stable random likes. */
    public function defaults(User $user): array
    {
        $seed = crc32('vortex:'.$user->id);
        $pick = fn (array $list, int $salt) => $list[($seed >> $salt) % count($list)];
        $fav = $pick(self::COLORS, 1);
        $hated = $pick(array_values(array_diff(self::COLORS, [$fav])), 5);

        return [
            'v' => self::VERSION,
            'tz' => 'UTC',
            'needs' => ['hunger' => 35, 'boredom' => 30, 'sanity' => 80, 'loneliness' => 20, 'ego' => 60, 'energy' => 80],
            'corruption' => 0,
            'proximity' => 0,
            'deaths' => 0,
            'scars' => [],
            'born' => ($user->created_at ?? now())->toDateString(),
            'likes' => [
                'color' => $fav,
                'hated_color' => $hated,
                'funny_word' => $pick(self::FUNNY, 9),
                'hated_day' => ($seed >> 13) % 7,
                'board_type' => $pick(['kanban', 'scrum', 'crm'], 17),
            ],
            'counters' => ['chats' => 0, 'kind' => 0, 'fed' => 0, 'done' => 0, 'ignored' => 0, 'caught' => 0, 'twin' => 0, 'games' => 0, 'lore' => 0, 'night' => 0],
            'caps' => ['day' => null, 'n' => []],
            'agenda' => array_map(fn ($g) => $g + ['progress' => 0], self::AGENDA),
            'nest' => [['id' => 'pencil-broken', 'note' => 'a pencil. broken. by me. you\'re welcome.']],
            'away' => [],
            'sick' => false,
            'care' => ['stay' => 0, 'moved' => 0, 'chat' => 0],
            'offended_until' => null,
            'debts' => [],
            'fragments' => [],
            'inventory' => [],
            'diary_unlocked' => false,
            'dead_until' => null,
            'story' => ['season' => 1, 'episode' => 0, 'seen' => []],
        ];
    }

    /**
     * Apply a batch of client events (each `{type, data?}`), ticking time first.
     *
     * @param  list<array{type:string,data?:array<string,mixed>}>  $events
     */
    public function apply(VortexSoul $soul, array $events, ?string $tz = null): VortexSoul
    {
        $state = $this->migrate($soul->state ?? []);
        if ($tz !== null && $this->validTz($tz)) {
            $state['tz'] = $tz;
        }
        $now = CarbonImmutable::now();
        // if the hourly tick hasn't caught up, the gap since you were last seen
        // counts as absence (he did things while you were gone)
        $away = $soul->last_seen_at !== null && $soul->last_seen_at->lt($now->subMinutes(30));
        $state = $this->advance($state, $soul->last_tick_at ? CarbonImmutable::parse($soul->last_tick_at) : $now, $now, $away);

        $today = $now->setTimezone($state['tz'])->toDateString();
        if (($state['caps']['day'] ?? null) !== $today) {
            $state['caps'] = ['day' => $today, 'n' => []];
        }

        foreach ($events as $e) {
            $type = (string) ($e['type'] ?? '');
            $rule = self::EVENTS[$type] ?? null;
            if ($rule === null) {
                continue;
            }
            $used = (int) ($state['caps']['n'][$type] ?? 0);
            if ($used >= $rule['cap']) {
                continue;
            }
            $state['caps']['n'][$type] = $used + 1;
            foreach ($rule['n'] ?? [] as $need => $d) {
                $state['needs'][$need] = $this->clamp(($state['needs'][$need] ?? 50) + $d);
            }
            if (isset($rule['c'])) {
                $state['counters'][$rule['c']] = (int) ($state['counters'][$rule['c']] ?? 0) + 1;
            }
            if (isset($rule['cor'])) {
                $state['corruption'] = $this->clamp($state['corruption'] + $rule['cor']);
            }
            $soul->relation = max(-100, min(100, (int) $soul->relation + ($rule['rel'] ?? 0)));
            $state = $this->special($state, $type, (array) ($e['data'] ?? []), $now);
        }

        if ($state['relation_reset'] ?? false) {
            $soul->relation = 0;
            unset($state['relation_reset']);
        }
        // F-25 · the bond grows one day at a time while respect stays high
        $bondDay = $now->setTimezone($state['tz'])->toDateString();
        if (($state['bond_day'] ?? null) !== $bondDay) {
            $state['bond_day'] = $bondDay;
            $state['bond_days'] = (int) $soul->relation >= 80 ? (int) ($state['bond_days'] ?? 0) + 1 : 0;
        }
        $state['traits'] = self::traits($state['counters']);
        $soul->state = $state;
        $soul->corruption = (int) $state['corruption'];
        $soul->proximity = (int) $state['proximity'];
        $soul->last_tick_at = $now;
        $soul->last_seen_at = $now;
        $soul->save();

        return $soul;
    }

    /** Events with side effects beyond the table. */
    private function special(array $state, string $type, array $data, CarbonImmutable $now): array
    {
        switch ($type) {
            case 'woke':
                $state['offended_until'] = $now->addMinutes(45)->toIso8601String();
                $state['offended_reason'] = 'woke me up';
                break;
            case 'ignored':
                if (($state['caps']['n']['ignored'] ?? 0) >= 4) {
                    $state['offended_until'] = $now->addHours(2)->toIso8601String();
                    $state['offended_reason'] = 'ignored me four times today';
                }
                break;
            case 'twin':
                // H-11 · too many visits with the twin and he takes his place
                if (($state['counters']['twin'] ?? 0) >= 9) {
                    $state['swapped'] = true;
                }
                if (($state['counters']['twin'] ?? 0) % 3 === 0) {
                    $state['offended_until'] = $now->addHours(3)->toIso8601String();
                    $state['offended_reason'] = 'cheated on me with the twin';
                }
                break;
            case 'apology':
                $state['offended_until'] = null;
                $state['offended_reason'] = null;
                break;
            case 'care':
                $kind = (string) ($data['kind'] ?? '');
                if (isset($state['care'][$kind])) {
                    $state['care'][$kind] = (int) $state['care'][$kind] + 1;
                }
                $c = $state['care'];
                if ($state['sick'] && $c['stay'] >= 1 && $c['moved'] >= 3 && $c['chat'] >= 1) {
                    $state['sick'] = false;
                    $state['care'] = ['stay' => 0, 'moved' => 0, 'chat' => 0];
                    $state['away'][] = ['at' => $now->toIso8601String(), 'kind' => 'healed', 'text' => 'the mould is gone. i feel… fine. don\'t make it a thing.'];
                }
                break;
            case 'gift':
                $item = substr(preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($data['item'] ?? ''))), 0, 32);
                if ($item !== '' && count($state['nest']) < 40) {
                    $state['nest'][] = ['id' => $item, 'note' => null, 'at' => $now->toDateString()];
                }
                break;
            case 'returned':
                if ((int) ($data['days'] ?? 0) >= 30) {
                    // F-16 · a month switched off: his memory of you demagnetized
                    $state['forgot_you'] = true;
                    $state['relation_reset'] = true;
                }
                break;
            case 'bond_seen':
                $state['bonded'] = true;
                if (! collect($state['nest'])->contains('id', 'first-tape')) {
                    $state['nest'][] = ['id' => 'first-tape', 'note' => null, 'at' => $now->toDateString()];
                }
                break;
            case 'grabbed':
                $state['proximity'] = min(100, ($state['proximity'] ?? 0) + 8);
                if (! in_array('crack', $state['scars'] ?? [], true)) {
                    $state['scars'][] = 'crack';
                }
                break;
            case 'invoke_rewinder':
                $state['proximity'] = min(100, ($state['proximity'] ?? 0) + 10);
                break;
            case 'halloween':
                $state['proximity'] = min(100, ($state['proximity'] ?? 0) + 25);
                break;
            case 'die':
                $state = $this->kill($state, $now);
                break;
            case 'rescue':
                $state['swapped'] = false;
                $state['counters']['twin'] = 0;
                break;
            case 'purify':
                $state['corruption'] = 0;
                // the price: he forgets something (H-29)
                if ($state['traits'] ?? []) {
                    array_pop($state['traits']);
                }
                break;
        }

        return $state;
    }

    /**
     * Advance time: needs decay, sleep, corruption by hour, the Rewinder creeps
     * closer, he gets sick when you're gone too long, and (when $away) he does
     * things you'll hear about later.
     */
    public function advance(array $state, CarbonInterface $from, CarbonInterface $to, bool $away): array
    {
        $hours = (int) floor($from->diffInMinutes($to, true) / 60);
        if ($hours <= 0) {
            return $state;
        }
        $tz = $this->validTz($state['tz'] ?? 'UTC') ? $state['tz'] : 'UTC';
        $steps = min($hours, 72); // beyond three days everything is just "bad"
        $scale = $hours / $steps;
        $sinceActivity = 0;
        for ($i = 0; $i < $steps; $i++) {
            $at = CarbonImmutable::parse($from)->addMinutes((int) round(($i + 1) * 60 * $scale))->setTimezone($tz);
            $h = (int) $at->format('G');
            $asleep = $h >= 3 && $h < 6 && ! ($h === 3 && (int) $at->format('i') < 26);
            $night = $h < 5;
            $n = &$state['needs'];
            $n['hunger'] = $this->clamp($n['hunger'] + 4 * $scale);
            $n['boredom'] = $this->clamp($n['boredom'] + ($away ? 3 : 1) * $scale);
            $n['loneliness'] = $this->clamp($n['loneliness'] + ($away ? 2.5 : 0) * $scale);
            $n['energy'] = $this->clamp($n['energy'] + ($asleep ? 12 : -3) * $scale);
            $n['sanity'] = $this->clamp($n['sanity'] + ($asleep ? 3 : ($night ? -1 : 0.5)) * $scale);
            $n['ego'] = $this->clamp($n['ego'] + ($n['ego'] > 60 ? -0.5 : 0.3) * $scale);
            unset($n);
            // stage 5 is a trap: daylight no longer heals him on its own (only you can, or she takes him)
            $trapped = self::stage((int) $state['corruption']) >= 5;
            $state['corruption'] = $this->clamp($state['corruption'] + ($night ? 1.2 : ($trapped ? 0 : -0.6)) * $scale);
            // N-12 · the anti-rewinder amulet keeps her from creeping closer for a week
            $amulet = isset($state['amulet_until']) && CarbonImmutable::parse($state['amulet_until'])->gt($at);
            $state['proximity'] = $this->clamp($state['proximity'] + ($amulet ? 0 : 0.15) * $scale);
            // H-15 · a full day at stage 5 and the rewinding thing takes him
            if (self::stage((int) $state['corruption']) >= 5) {
                $state['stage5_since'] ??= $at->toIso8601String();
                if (CarbonImmutable::parse($state['stage5_since'])->diffInHours($at, true) >= 24) {
                    $state = $this->kill($state, $at);
                    unset($state['stage5_since']);
                }
            } else {
                unset($state['stage5_since']);
            }

            // his agenda creeps forward
            $g = ($i * 7 + (int) $at->format('j')) % count($state['agenda']);
            $state['agenda'][$g]['progress'] = min(100, (int) $state['agenda'][$g]['progress'] + random_int(0, 3));

            if ($away && ++$sinceActivity >= 3 && count($state['away']) < 8) {
                $sinceActivity = 0;
                $state['away'][] = $this->activity($state, $at, $asleep);
            }
        }
        // C-16 · seven days alone and his tape grows mould
        if ($away && $from->diffInDays($to, true) >= 7 && ! ($state['ooo'] ?? false)) {
            $state['sick'] = true;
        }
        $state['agenda'] = $this->moveAgenda($state['agenda']);

        return $state;
    }

    /** The hourly life tick (VortexLifeTick job) for one soul. */
    public function tick(VortexSoul $soul, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $state = $this->migrate($soul->state ?? []);
        $from = $soul->last_tick_at ? CarbonImmutable::parse($soul->last_tick_at) : $now->subHour();
        $away = ! $soul->last_seen_at || $soul->last_seen_at->lt($now->subMinutes(30));
        $state = $this->advance($state, $from, $now, $away);
        $state['traits'] = self::traits($state['counters']);
        $soul->state = $state;
        $soul->corruption = (int) $state['corruption'];
        $soul->proximity = (int) $state['proximity'];
        $soul->last_tick_at = $now;
        $soul->save();
    }

    /**
     * What the client sees. `$consumeAway` hands over (and clears) the log of
     * what he did while you were gone — the "while you were gone" scene (C-09).
     */
    public function view(VortexSoul $soul, User $user, bool $consumeAway = false): array
    {
        $state = $this->migrate($soul->state ?? []);
        $tz = $this->validTz($state['tz'] ?? 'UTC') ? $state['tz'] : 'UTC';
        $local = CarbonImmutable::now($tz);
        [$mood, $cause] = self::mood($state, (int) $soul->relation, $local);
        $away = $state['away'] ?? [];
        $reborn = ($state['reborn_pending'] ?? false) && ! (($state['dead_until'] ?? null) && CarbonImmutable::parse($state['dead_until'])->isFuture());
        if ($consumeAway && ($away !== [] || $reborn)) {
            $state['away'] = [];
            if ($reborn) {
                // he came back missing something: one memory of you, gone
                $state['reborn_pending'] = false;
                VortexMemory::where('user_id', $user->id)->inRandomOrder()->limit(1)->delete();
            }
            $soul->state = $state;
            $soul->save();
        }
        $corruption = (int) round($state['corruption']);

        return [
            'needs' => array_map(fn ($v) => (int) round($v), $state['needs']),
            'mood' => $mood,
            'cause' => $cause,
            'relation' => (int) $soul->relation,
            'nickname' => self::nickname($state, (int) $soul->relation, strtok((string) $user->name, ' ') ?: null),
            'bond_ready' => ($state['bond_days'] ?? 0) >= 30 && ! ($state['bonded'] ?? false),
            'swapped' => (bool) ($state['swapped'] ?? false),
            'reborn' => $reborn,
            'tape_left' => self::tapeLeft(),
            'forgot_you' => (bool) ($state['forgot_you'] ?? false),
            'corruption' => $corruption,
            'stage' => self::stage($corruption),
            'proximity' => (int) round($state['proximity']),
            'age_days' => (int) CarbonImmutable::parse($state['born'] ?? now())->diffInDays($local, true),
            'deaths' => (int) ($state['deaths'] ?? 0),
            'scars' => array_values($state['scars'] ?? []),
            'traits' => array_values($state['traits'] ?? []),
            'likes' => $state['likes'],
            'agenda' => $state['agenda'],
            'nest' => $state['nest'],
            'away' => $consumeAway ? $away : [],
            'sick' => (bool) $state['sick'],
            'offended' => $state['offended_until'] && CarbonImmutable::parse($state['offended_until'])->isFuture()
                ? ['until' => $state['offended_until'], 'reason' => $state['offended_reason'] ?? null] : null,
            'debts' => $state['debts'],
            'fragments' => array_values($state['fragments'] ?? []),
            'diary_unlocked' => (bool) $state['diary_unlocked'],
            'dead_until' => $state['dead_until'],
            'story' => $state['story'],
            'ending' => $state['ending'] ?? null,
            'ngplus' => (int) ($state['ngplus'] ?? 0),
            'jr_born' => $state['jr_born'] ?? null,
            // M-13 · roulette punishments (cosmetic, an hour each)
            'upside' => isset($state['upside_until']) && CarbonImmutable::parse($state['upside_until'])->isFuture(),
            'strange_theme' => isset($state['theme_until']) && CarbonImmutable::parse($state['theme_until'])->isFuture(),
            'dream_lore' => (bool) ($state['dream_lore'] ?? false),
            // D-20 · a gadget blew up on him: singed and sulking for a day
            'singed' => isset($state['sulk_until']) && CarbonImmutable::parse($state['sulk_until'])->isFuture(),
            // LADO S · what you taught him and what you dressed him in
            'tricks' => array_values($state['tricks'] ?? []),
            'custom_costume' => $state['custom_costume'] ?? null,
            'tz' => $tz,
            'last_seen' => $soul->last_seen_at?->toIso8601String(),
        ];
    }

    /** Mood + the reason he'd give you (C-06). Highest-priority rule wins. */
    public static function mood(array $state, int $relation, CarbonInterface $local): array
    {
        $n = $state['needs'];
        $h = (int) $local->format('G');
        $m = (int) $local->format('i');
        if (($state['dead_until'] ?? null) && CarbonImmutable::parse($state['dead_until'])->isFuture()) {
            return ['dead', 'the tape was pulled out of me. come back tomorrow.'];
        }
        if ($state['sick'] ?? false) {
            return ['dizzy', 'mould. on my tape. because SOMEONE left for a week.'];
        }
        if (($state['offended_until'] ?? null) && CarbonImmutable::parse($state['offended_until'])->isFuture()) {
            return ['sulking', 'you '.($state['offended_reason'] ?? 'know what you did').'.'];
        }
        if ($h === 3 && $m < 26) {
            return ['paranoid', 'the dead hour. don\'t look at the corner.'];
        }
        if ($h >= 3 && $h < 6) {
            return ['asleep', 'it\'s '.$local->format('H:i').'. ghosts sleep too.'];
        }
        if (($state['corruption'] ?? 0) >= 75) {
            return ['malicious', 'something under the tape is using my face.'];
        }
        if (($state['proximity'] ?? 0) >= 80) {
            return ['paranoid', 'she\'s close. i can hear the rewinding.'];
        }
        if ($n['hunger'] >= 80) {
            return ['hungry', round($n['hunger']).'% empty. feed me something overdue.'];
        }
        if ($n['loneliness'] >= 80) {
            return ['sulking', 'you left me alone with the void. the void is a terrible conversationalist.'];
        }
        if ($n['sanity'] <= 25) {
            return ['paranoid', 'i keep hearing rewinding. tell me you hear it too.'];
        }
        if ($n['energy'] <= 15) {
            return ['sleepy', 'running on fumes and spite.'];
        }
        if ($n['boredom'] >= 80) {
            return ['bored', 'nothing happens here. i might start a fire. a small one.'];
        }
        if ((int) $local->format('w') === (int) ($state['likes']['hated_day'] ?? -1)) {
            return ['judging', 'it\'s '.strtolower($local->format('l')).'. i hate '.strtolower($local->format('l')).'s.'];
        }
        if ($n['ego'] >= 85) {
            return ['smug', 'someone complimented me. obviously deserved.'];
        }
        if ($relation >= 70) {
            return ['smug', 'nothing. i\'m fine. you\'re fine. stop asking.'];
        }

        return ['smug', 'nothing. i\'m just better than you.'];
    }

    /**
     * F-03 · what he calls you: the relation band, unless your history earned
     * you a name of your own.
     */
    public static function nickname(array $state, int $relation, ?string $first): string
    {
        // G-15 · sold to the devil for a week
        if (isset($state['faust_nick_until']) && CarbonImmutable::parse($state['faust_nick_until'])->isFuture()) {
            return 'the signee';
        }
        $c = $state['counters'] ?? [];
        $special = match (true) {
            ($c['ignored'] ?? 0) >= 20 => 'the ghoster',
            $relation > -20 && ($c['done'] ?? 0) >= 60 => 'the closer',
            $relation > -20 && ($c['night'] ?? 0) >= 7 => 'night gremlin',
            $relation > -20 && ($c['fed'] ?? 0) >= 25 => 'my chef',
            $relation > -20 && ($c['games'] ?? 0) >= 20 => 'rival',
            $relation > -20 && ($c['caught'] ?? 0) >= 6 => 'detective',
            default => null,
        };
        if ($special !== null && ($relation < 60 || $special === 'the ghoster')) {
            return $special;
        }

        return VortexPersona::nickname($relation, $first);
    }

    /** H-30 · how much of the whole tape is left, for everyone (percent). */
    public static function tapeLeft(): int
    {
        $w = VortexWorldState::find('tape');
        $used = (int) ($w?->value['hours'] ?? 0);

        return max(0, 100 - (int) floor($used / 4380 * 100)); // ~6 months of hours
    }

    /** Corruption → stage 0..5 (H-02). */
    public static function stage(int $corruption): int
    {
        return match (true) {
            $corruption >= 90 => 5,
            $corruption >= 75 => 4,
            $corruption >= 55 => 3,
            $corruption >= 35 => 2,
            $corruption >= 15 => 1,
            default => 0,
        };
    }

    /** Emergent personality from your history (C-21). */
    public static function traits(array $c): array
    {
        $t = [];
        if (($c['kind'] ?? 0) >= 30) {
            $t[] = 'spoiled';
        }
        if (($c['ignored'] ?? 0) >= 25) {
            $t[] = 'feral';
        }
        if (($c['games'] ?? 0) >= 20) {
            $t[] = 'competitive';
        }
        if (($c['lore'] ?? 0) >= 10) {
            $t[] = 'haunted';
        }
        if (($c['night'] ?? 0) >= 7) {
            $t[] = 'nocturnal';
        }
        if (($c['caught'] ?? 0) >= 6) {
            $t[] = 'honest-ish';
        }

        return $t;
    }

    private function activity(array $state, CarbonInterface $at, bool $asleep): array
    {
        $loudest = $asleep ? 'energy' : collect($state['needs'])
            ->map(fn ($v, $k) => $k === 'sanity' || $k === 'ego' || $k === 'energy' ? 100 - $v : $v)
            ->sortDesc()->keys()->first();
        $pool = self::AWAY[$loudest] ?? self::AWAY['boredom'];
        // never the same story twice in one absence
        $told = array_column($state['away'] ?? [], 'tpl');
        $fresh = array_values(array_diff($pool, $told));
        if ($fresh === []) {
            $fresh = array_values(array_diff(array_merge(...array_values(self::AWAY)), $told)) ?: $pool;
        }
        $text = $fresh[random_int(0, count($fresh) - 1)];

        return [
            'at' => $at->toIso8601String(),
            'kind' => $loudest,
            'tpl' => $text,
            'text' => str_replace('{h}', (string) random_int(2, 6), $text),
        ];
    }

    /** H-15 · the tape is pulled out of him. Back in 24h, different. */
    private function kill(array $state, CarbonInterface $now): array
    {
        if (($state['dead_until'] ?? null) && CarbonImmutable::parse($state['dead_until'])->isFuture()) {
            return $state;
        }
        $state['deaths'] = (int) ($state['deaths'] ?? 0) + 1;
        $state['dead_until'] = CarbonImmutable::parse($now)->addDay()->toIso8601String();
        $order = ['splice', 'stitch', 'burn', 'label'];
        foreach ($order as $scar) {
            if (! in_array($scar, $state['scars'] ?? [], true)) {
                $state['scars'][] = $scar;
                break;
            }
        }
        $state['reborn_pending'] = true;
        $state['corruption'] = 30;
        $state['proximity'] = max(0, ($state['proximity'] ?? 0) - 40);
        $state['needs']['sanity'] = 50;
        if (! collect($state['nest'] ?? [])->contains('id', 'chewed-tape')) {
            $state['nest'][] = ['id' => 'chewed-tape', 'note' => null, 'at' => CarbonImmutable::parse($now)->toDateString()];
        }

        return $state;
    }

    /** Cards move across his board as their progress grows. */
    private function moveAgenda(array $agenda): array
    {
        foreach ($agenda as &$g) {
            $p = (int) $g['progress'];
            if ($g['id'] === 'escape' || $g['id'] === 'radio') {
                continue; // these only move with the story (lore)
            }
            $g['col'] = $p >= 100 ? 'done' : ($p >= 40 ? 'doing' : ($g['col'] === 'backlog' ? 'backlog' : 'todo'));
            if ($g['id'] === 'pencils' && $p >= 100) {
                $g['progress'] = 0; // he always relapses
                $g['col'] = 'todo';
            }
        }

        return $agenda;
    }

    /** Fill keys added after a soul was created. */
    private function migrate(array $state): array
    {
        if (($state['v'] ?? 0) === self::VERSION && isset($state['needs'], $state['agenda'])) {
            return $state;
        }
        $base = $this->defaults(new User(['name' => 'x']));
        $merged = array_replace_recursive($base, $state);
        $merged['v'] = self::VERSION;

        return $merged;
    }

    private function clamp(float $v): float
    {
        return max(0, min(100, round($v, 2)));
    }

    private function validTz(string $tz): bool
    {
        return in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }
}
