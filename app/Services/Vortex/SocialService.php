<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexSoul;
use App\Infrastructure\Models\VortexWorldState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * LADO P · A SOCIETY OF GHOSTS. Every user's Vortex knows the others on the
 * team — but only the ones whose owners opted in ("my Vortex may visit and be
 * seen"). The ghosts gossip about each other's GHOSTS (moods, deaths,
 * costumes), never about anyone's work or life; nothing here ranks people's
 * productivity. Relations, the elder, factions and the cult, group pranks,
 * the static choir, team weather, plaques, the project spirit, collective
 * discovery, the (absurd) contempt ranking, and the workspace off-switch.
 */
final class SocialService
{
    public const FACTIONS = [
        'recorders' => ['the recorders', 'serve the record head: make, collect, keep.'],
        'listeners' => ['the listeners', 'serve the play head: lore, mysteries, the tape\'s secrets.'],
        'demagnetised' => ['the demagnetised', 'flirt with the erase head: chaos, dark, static.'],
    ];

    public function __construct(private readonly SoulService $souls, private readonly EconomyService $econ) {}

    public function moderation(): array
    {
        return array_replace(['social_off' => false, 'polite_only' => false, 'reports' => []], VortexWorldState::find('moderation')?->value ?? []);
    }

    public function enabled(VortexSoul $soul): bool
    {
        return ! $this->moderation()['social_off'] && (bool) ($soul->state['social'] ?? false);
    }

    public function setOptIn(VortexSoul $soul, bool $on): void
    {
        $soul->state = [...$soul->state, 'social' => $on];
        $soul->save();
    }

    /** Teammates' ghosts you may see (they opted in, you opted in). */
    public function ghosts(User $user, VortexSoul $mine): array
    {
        if (! $this->enabled($mine)) {
            return [];
        }
        $ids = Teammates::of($user->id);

        return VortexSoul::whereIn('user_id', $ids)->get()->filter(fn ($s) => $this->enabled($s))
            ->map(fn ($s) => $this->publicGhost($s))->values()->all();
    }

    /** What another ghost shows of itself: the ghost, never the person's work. */
    public function publicGhost(VortexSoul $s): array
    {
        $st = $s->state ?? [];
        $owner = User::find($s->user_id);
        $dead = isset($st['dead_until']) && CarbonImmutable::parse($st['dead_until'])->isFuture();

        return [
            'user_id' => (int) $s->user_id,
            'owner' => (string) (strtok((string) $owner?->name, ' ') ?: 'someone'),
            'mood' => $owner ? ($this->souls->view($s, $owner)['mood'] ?? 'smug') : 'smug',
            'age_days' => (int) CarbonImmutable::parse($st['born'] ?? now())->diffInDays(now(), true),
            'deaths' => (int) ($st['deaths'] ?? 0),
            'dead' => $dead,
            'scars' => array_values($st['scars'] ?? []),
            'costume' => $st['equip']['costume'] ?? null,
            'eye' => $st['equip']['eye'] ?? null,
            'faction' => $st['faction'] ?? null,
            'traits' => array_values(array_slice($st['traits'] ?? [], 0, 3)),
            'ending' => $st['ending'] ?? null,
            'level' => $this->econ->level($s)['level'],
            'plaques' => count($st['plaques'] ?? []),
        ];
    }

    /** P-01/P-02 · two ghosts met: the relation drifts with their temperaments. */
    public function met(User $user, VortexSoul $mine, int $other): ?array
    {
        if (! $this->enabled($mine) || ! in_array($other, Teammates::of($user->id), true)) {
            return null;
        }
        $theirs = $this->souls->for(User::findOrFail($other));
        if (! $this->enabled($theirs)) {
            return null;
        }
        $day = CarbonImmutable::now()->toDateString();
        $rel = $mine->state['ghost_rel'][$other] ?? ['score' => 0, 'met' => 0, 'day' => null];
        if ($rel['day'] !== $day) {
            // same temperament attracts, opposite repels — plus a little chaos
            $a = $mine->state['traits'] ?? [];
            $b = $theirs->state['traits'] ?? [];
            $drift = (count(array_intersect($a, $b)) * 6) - (count(array_diff($a, $b)) * 2) + random_int(-6, 8);
            $rel = ['score' => max(-100, min(100, $rel['score'] + $drift)), 'met' => $rel['met'] + 1, 'day' => $day];
            $s = $mine->state;
            $s['ghost_rel'][$other] = $rel;
            $mine->state = $s;
            $mine->save();
        }
        $kind = $this->kind($rel['score']);
        $g = $this->publicGhost($theirs);

        return ['ghost' => $g, 'score' => $rel['score'], 'kind' => $kind, 'exchange' => $this->exchange($kind, $g)];
    }

    private function kind(int $score): string
    {
        return match (true) {
            $score >= 70 => 'love',
            $score >= 30 => 'friends',
            $score <= -60 => 'contempt',
            $score <= -30 => 'rivals',
            default => 'strangers',
        };
    }

    /** Three lines between your ghost and theirs, by how they get along. */
    private function exchange(string $kind, array $g): array
    {
        $o = $g['owner'];

        return match ($kind) {
            'love' => ['…hi.', "{$o}'s ghost: hi. *reel eyes*", 'this is mortifying. don\'t look at us.'],
            'friends' => ["{$o}'s ghost! my guy.", "{$o}'s ghost: prank tonight?", 'obviously.'],
            'rivals' => ["oh. it's you.", "{$o}'s ghost: nice hat. idiot.", 'i will haunt your column.'],
            'contempt' => ['…', "{$o}'s ghost: …", 'we don\'t speak. we haven\'t spoken since the incident.'],
            default => ['who are you.', "{$o}'s ghost: a ghost. you?", 'same. weird.'],
        };
    }

    /** P-03 · gossip about the other ghosts. Never about the people. */
    public function gossip(User $user, VortexSoul $mine): array
    {
        $out = [];
        foreach ($this->ghosts($user, $mine) as $g) {
            $o = $g['owner'];
            $out[] = match (true) {
                $g['dead'] => "{$o}'s ghost is dead right now. the wake is below. bring a flower. or don't. i wouldn't.",
                $g['mood'] === 'sleepy' || $g['mood'] === 'asleep' => "{$o}'s ghost has been asleep for ages. depressed. or lazy. same thing.",
                $g['deaths'] >= 2 => "{$o}'s ghost has died {$g['deaths']} times. show-off.",
                $g['costume'] !== null => "{$o}'s ghost is wearing ".str_replace('costume-', 'a ', (string) $g['costume']).' outfit. in public.',
                in_array('erased', $g['scars'], true) => "{$o}'s ghost was erased once. came back. we don't talk about it.",
                $g['ending'] === 'free' => "{$o}'s ghost has a kid now. jr. annoying little thing. i love him. i don't.",
                default => "{$o}'s ghost is {$g['mood']}. as usual. predictable.",
            };
        }
        // P-17 · collective discovery: someone found something
        foreach (Cache::get('vortex:found:'.$user->id, []) as $f) {
            $out[] = "someone on your team found something {$f}. they won't say what.";
        }
        Cache::forget('vortex:found:'.$user->id);

        return array_slice($out, 0, 6);
    }

    /** P-17 · tell the team (no spoilers: only where). */
    public function announceDiscovery(int $userId, string $where): void
    {
        foreach (Teammates::of($userId) as $mate) {
            $k = 'vortex:found:'.$mate;
            Cache::put($k, array_slice([...Cache::get($k, []), "in {$where}"], -5), now()->addWeek());
        }
    }

    /** P-04 · the oldest living ghost on the team is the Elder. */
    public function elder(User $user, VortexSoul $mine): ?array
    {
        $all = collect([$this->publicGhost($mine), ...$this->ghosts($user, $mine)])->reject(fn ($g) => $g['dead']);

        return $all->sortByDesc('age_days')->first();
    }

    /** P-05/P-06 · join a faction (level 20), or the cult (30 fragments). */
    public function join(VortexSoul $soul, string $faction): array
    {
        $s = $soul->state;
        if ($faction === 'cult') {
            if (count($s['fragments'] ?? []) < 30) {
                return ['ok' => false, 'reason' => 'nobody has invited you. yet.'];
            }
            $s['faction'] = 'cult';
            $s['cult_since'] = now()->toIso8601String();
            $soul->relation = max(-100, (int) $soul->relation - 15); // he's afraid of you now
            $soul->state = $s;
            $soul->save();

            return ['ok' => true, 'faction' => 'cult', 'line' => 'you WANT her to come? …i\'m going to sit over here. away from you.'];
        }
        if (! isset(self::FACTIONS[$faction])) {
            return ['ok' => false, 'reason' => 'no such faction.'];
        }
        if ($this->econ->level($soul)['level'] < 20) {
            return ['ok' => false, 'reason' => 'level 20 first. factions don\'t take children.'];
        }
        if (($s['faction'] ?? null) === 'cult') {
            // P-06 · betraying the cult has a price
            $s['scars'] = array_values(array_unique([...($s['scars'] ?? []), 'betrayal']));
            $s['proximity'] = min(100, ($s['proximity'] ?? 0) + 30);
        }
        $s['faction'] = $faction;
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'faction' => $faction];
    }

    /** P-05 · this month's faction contest on the team: echoes gathered. */
    public function factionStandings(User $user): array
    {
        $ids = [$user->id, ...Teammates::of($user->id)];
        $souls = VortexSoul::whereIn('user_id', $ids)->get()->filter(fn ($s) => isset($s->state['faction']));
        $since = now()->startOfMonth();
        $totals = [];
        foreach ($souls as $s) {
            $f = $s->state['faction'];
            $totals[$f] = ($totals[$f] ?? 0) + (int) DB::table('vortex_ledger')->where('user_id', $s->user_id)
                ->where('currency', 'echoes')->where('amount', '>', 0)->where('created_at', '>=', $since)->sum('amount');
        }
        arsort($totals);

        return $totals;
    }

    /** P-07 · a coordinated static attack: it fires when a second teammate joins in. */
    public function prank(User $user, VortexSoul $mine, int $target): array
    {
        if (! $this->enabled($mine) || ! in_array($target, Teammates::of($user->id), true)) {
            return ['ok' => false, 'reason' => 'they\'re not on your team. or not haunted.'];
        }
        $k = 'vortex:prank:'.$target;
        $p = Cache::get($k, ['attackers' => [], 'fired' => false]);
        $p['attackers'] = array_values(array_unique([...$p['attackers'], $user->id]));
        if (count($p['attackers']) >= 2) {
            $p['fired'] = true;
        }
        Cache::put($k, $p, now()->addMinutes(10));

        return ['ok' => true, 'armed' => count($p['attackers']), 'fired' => $p['fired']];
    }

    /** What's waiting for you: a static attack, plaques, gossip-worthy news. */
    public function inbox(User $user, VortexSoul $mine): array
    {
        $k = 'vortex:prank:'.$user->id;
        $p = Cache::get($k);
        $attack = null;
        if ($p && $p['fired'] && $this->enabled($mine)) {
            $attack = User::whereIn('id', $p['attackers'])->pluck('name')->map(fn ($n) => strtok((string) $n, ' '))->all();
            Cache::forget($k);
        }
        $new = array_values(array_filter($mine->state['plaques'] ?? [], fn ($pl) => ! ($pl['seen'] ?? false)));
        if ($new) {
            $s = $mine->state;
            $s['plaques'] = array_map(fn ($pl) => [...$pl, 'seen' => true], $s['plaques']);
            $mine->state = $s;
            $mine->save();
        }

        return ['attack' => $attack, 'plaques' => $new];
    }

    /** P-08 · the static choir: three people on the same board each hold a note at once. */
    public function choir(User $user, VortexSoul $mine, int $boardId, int $note): array
    {
        $board = Board::find($boardId);
        if (! $board || ! $board->isAccessibleBy($user->id)) {
            return ['ok' => false];
        }
        $k = 'vortex:choir:'.$boardId;
        $held = array_filter(Cache::get($k, []), fn ($h) => $h['t'] > microtime(true) - 6);
        $held[$user->id] = ['t' => microtime(true), 'note' => $note % 12];
        Cache::put($k, $held, 60);
        $chord = count($held) >= 3 && count(array_unique(array_column($held, 'note'))) >= 3;
        if ($chord && ! ($mine->state['choir_done'] ?? false)) {
            $s = $mine->state;
            $s['choir_done'] = true;
            $mine->state = $s;
            $mine->save();
            $this->econ->earn($mine, 'secret_room', 3);
        }

        return ['ok' => true, 'voices' => count($held), 'chord' => $chord];
    }

    /** P-09 · the team's weather (for the Below and the radio). */
    public function weather(User $user): string
    {
        $ids = [$user->id, ...Teammates::of($user->id)];
        $boards = Board::whereIn('user_id', $ids)->pluck('id');
        if ($boards->isEmpty()) {
            return 'clear';
        }
        $open = Card::whereIn('board_id', $boards)->whereNull('archived_at')->whereNull('done_at')->count();
        $late = Card::whereIn('board_id', $boards)->whereNull('archived_at')->whereNull('done_at')->where('due_date', '<', now())->count();
        $done = Card::whereIn('board_id', $boards)->where('done_at', '>=', now()->subWeek())->count();

        return match (true) {
            $open > 0 && $late / $open >= 0.3 => 'rain',
            $done >= 10 && $late === 0 => 'sun',
            default => 'clear',
        };
    }

    /** P-14 · a golden plaque for a teammate (one per giver per week). */
    public function plaque(User $from, int $to, string $reason): array
    {
        if (! in_array($to, Teammates::of($from->id), true)) {
            return ['ok' => false, 'reason' => 'only for your team.'];
        }
        $reason = trim(mb_substr($reason, 0, 120));
        if (mb_strlen($reason) < 3 || preg_match('/\b(nazi|retard|fag|puta|viado|vagabunda)\w*/iu', $reason)) {
            return ['ok' => false, 'reason' => 'the engraver refuses.'];
        }
        $soul = $this->souls->for(User::findOrFail($to));
        $s = $soul->state;
        $week = CarbonImmutable::now()->format('o-W');
        foreach ($s['plaques'] ?? [] as $p) {
            if ($p['from'] === $from->id && $p['week'] === $week) {
                return ['ok' => false, 'reason' => 'one plaque a week from you. make it count.'];
            }
        }
        $s['plaques'][] = ['from' => $from->id, 'from_name' => (string) strtok((string) $from->name, ' '), 'reason' => VortexSafety::polite($reason), 'week' => $week, 'at' => now()->toDateString(), 'seen' => false];
        $soul->state = $s;
        $soul->save();

        return ['ok' => true];
    }

    /** P-15 · the project's spirit: the sum of the team's ghosts, for a speech. */
    public function spirit(User $user, int $projectId): ?array
    {
        $p = Project::find($projectId);
        if (! $p) {
            return null;
        }
        $boards = Board::where('project_id', $projectId)->whereNull('archived_at')->get();
        if (! $boards->contains(fn ($b) => $b->isAccessibleBy($user->id))) {
            return null;
        }
        $owners = $boards->pluck('user_id')->merge(DB::table('board_shares')->whereIn('board_id', $boards->pluck('id'))->pluck('user_id'))->unique();
        $souls = VortexSoul::whereIn('user_id', $owners)->get();
        $total = Card::whereIn('board_id', $boards->pluck('id'))->whereNull('archived_at')->count();
        $done = Card::whereIn('board_id', $boards->pluck('id'))->whereNull('archived_at')->whereNotNull('done_at')->count();

        return [
            'project' => $p->name,
            'ghosts' => $souls->count(),
            'age' => (int) round($souls->avg(fn ($s) => CarbonImmutable::parse($s->state['born'] ?? now())->diffInDays(now(), true)) ?? 0),
            'deaths' => (int) $souls->sum(fn ($s) => (int) ($s->state['deaths'] ?? 0)),
            'progress' => $total ? (int) round($done / $total * 100) : 0,
        ];
    }

    /** P-18 · his private, absurd contempt ranking of the team's GHOSTS (opt-in). */
    public function contempt(User $user, VortexSoul $mine): array
    {
        if (! ($mine->state['contempt_on'] ?? false)) {
            return [];
        }
        $reasons = [
            'wears a hat indoors.', 'breathes too loud for a ghost.', 'has a better scar than me.',
            'said "synergy" once. unforgivable.', 'is too nice. suspicious.', 'smells like the twin.',
            'naps in the done column.', 'laughed at my rim.', 'owes me a token.',
        ];

        return collect($this->ghosts($user, $mine))
            ->sortBy(fn ($g) => crc32($g['user_id'].':'.now()->format('o-W')))
            ->values()
            ->map(fn ($g, $i) => ['rank' => $i + 1, 'ghost' => $g['owner']."'s ghost", 'why' => $reasons[crc32($g['owner'].$i) % count($reasons)]])
            ->all();
    }

    /** P-20 · workspace moderation (admins). */
    public function setModeration(array $patch): array
    {
        $w = VortexWorldState::firstOrNew(['key' => 'moderation']);
        $w->value = array_replace($this->moderation(), array_intersect_key($patch, array_flip(['social_off', 'polite_only'])));
        $w->save();

        return $this->moderation();
    }

    public function report(User $user, string $kind, string $text): void
    {
        $w = VortexWorldState::firstOrNew(['key' => 'moderation']);
        $v = $this->moderation();
        $v['reports'] = array_slice([...$v['reports'], ['by' => $user->id, 'kind' => $kind, 'text' => mb_substr($text, 0, 300), 'at' => now()->toIso8601String()]], -100);
        $w->value = $v;
        $w->save();
    }
}
