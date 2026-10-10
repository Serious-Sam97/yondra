<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexLedger;
use App\Infrastructure\Models\VortexScore;
use App\Infrastructure\Models\VortexSoul;
use App\Infrastructure\Models\VortexTrade;
use Carbon\CarbonImmutable;

/**
 * N-10 · ACHIEVEMENTS 2.0 and N-19 · THE ALBUM. 120 achievements (20 measures ×
 * 6 tiers), each a collectable tape label, in three albums — Side A (work),
 * Side B (chaos), Side C (lore). Everything is computed from server facts, so
 * nothing can be granted from the client. The lore ones are secret until you
 * earn them. The album (level, labels, sets, time together) can be shown to
 * the team, if you choose.
 */
final class AchievementService
{
    public const TIERS = [1, 5, 10, 25, 50, 100];

    private const TIER_NAME = ['first', 'regular', 'devoted', 'veteran', 'legend', 'myth'];

    /** measure => [album, label (one), label (many), secret] — %d is the number */
    public const MEASURES = [
        'done' => ['A', 'a card finished', '%d cards finished', false],
        'minutes' => ['A', 'an hour on tape', '%d hours on tape', false],
        'chats' => ['A', 'a conversation with him', '%d conversations with him', false],
        'level' => ['A', 'level one (welcome)', 'level %d', false],
        'days' => ['A', 'a day together', '%d days together', false],
        'kind' => ['A', 'a kind word', '%d kind words', false],
        'tokens' => ['B', 'a token earned', '%d tokens earned', false],
        'games' => ['B', 'an arcade run', '%d arcade runs', false],
        'night' => ['B', 'a late night', '%d late nights', false],
        'fed' => ['B', 'a card fed to him', '%d cards fed to him', false],
        'caught' => ['B', 'a lie caught', '%d lies caught', false],
        'costumes' => ['B', 'a costume', '%d costumes', false],
        'trades' => ['B', 'a trade with the team', '%d trades with the team', false],
        'fragments' => ['C', 'a fragment found', '%d fragments found', true],
        'episodes' => ['C', 'an episode watched', '%d episodes watched', true],
        'rooms' => ['C', 'a room below', '%d rooms below', true],
        'echoes' => ['C', 'an echo gathered', '%d echoes gathered', true],
        'tapes' => ['C', 'a radio tape kept', '%d radio tapes kept', true],
        'deaths' => ['C', 'a funeral', '%d funerals', true],
        'endings' => ['C', 'an ending seen', '%d endings seen', true],
    ];

    /** The raw numbers behind every measure. */
    public function measures(User $user, VortexSoul $soul): array
    {
        $s = $soul->state ?? [];
        $c = $s['counters'] ?? [];
        $earned = fn (string $cur) => (int) VortexLedger::where('user_id', $user->id)->where('currency', $cur)->where('amount', '>', 0)->sum('amount');

        return [
            'done' => (int) ($c['done'] ?? 0),
            'minutes' => intdiv($earned('minutes'), 60),
            'chats' => (int) ($c['chats'] ?? 0),
            'level' => app(EconomyService::class)->level($soul)['level'],
            'days' => (int) CarbonImmutable::parse($s['born'] ?? now())->diffInDays(now(), true),
            'kind' => (int) ($c['kind'] ?? 0),
            'tokens' => $earned('tokens'),
            'games' => VortexScore::where('user_id', $user->id)->count(),
            'night' => (int) ($c['night'] ?? 0),
            'fed' => (int) ($c['fed'] ?? 0),
            'caught' => (int) ($c['caught'] ?? 0),
            'costumes' => count(array_unique(array_filter($s['inventory'] ?? [], fn ($i) => str_starts_with($i, 'costume-')))),
            'trades' => VortexTrade::where('status', 'done')->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))->count(),
            'fragments' => count($s['fragments'] ?? []) + count($s['fragments_prev'] ?? []),
            'episodes' => count($s['story']['seen'] ?? []),
            'rooms' => count($s['below']['visited'] ?? []),
            'echoes' => $earned('echoes'),
            'tapes' => count($s['radio_tapes'] ?? []),
            'deaths' => (int) ($s['deaths'] ?? 0),
            'endings' => count($s['endings_seen'] ?? []),
        ];
    }

    /** All 120 with their state. Secret ones you haven't earned come back blank. */
    public function list(User $user, VortexSoul $soul): array
    {
        $m = $this->measures($user, $soul);
        // T-04 · achievements kept through a "forget everything"
        $kept = array_flip($soul->state['kept_achievements'] ?? []);
        $out = [];
        foreach (self::MEASURES as $key => [$album, $one, $many, $secret]) {
            foreach (self::TIERS as $t => $need) {
                $got = $m[$key] >= $need || isset($kept["{$key}-{$need}"]);
                $label = $need === 1 ? $one : sprintf($many, $need);
                $out[] = [
                    'id' => "{$key}-{$need}",
                    'album' => $album,
                    'title' => $got || ! $secret ? self::TIER_NAME[$t].' · '.$label : '? ? ?',
                    'got' => $got,
                    'secret' => $secret && ! $got,
                    'progress' => $got || ! $secret ? min(1, $m[$key] / $need) : 0,
                ];
            }
        }

        return $out;
    }

    /** N-19 · the album: what the profile shelf shows. */
    public function album(User $user, VortexSoul $soul): array
    {
        $list = $this->list($user, $soul);
        $econ = app(EconomyService::class);
        $albums = [];
        foreach (['A' => 'side a · work', 'B' => 'side b · chaos', 'C' => 'side c · lore'] as $k => $name) {
            $mine = array_values(array_filter($list, fn ($a) => $a['album'] === $k));
            $albums[] = ['id' => $k, 'name' => $name, 'got' => count(array_filter($mine, fn ($a) => $a['got'])), 'total' => count($mine), 'labels' => $mine];
        }

        return [
            'name' => (string) $user->name,
            'level' => $econ->level($soul)['level'],
            'days' => $this->measures($user, $soul)['days'],
            'albums' => $albums,
            'collections' => $econ->collections($soul),
            'public' => (bool) ($soul->state['album_public'] ?? false),
        ];
    }

    /** A teammate's album, only if they made it public. */
    public function teammateAlbum(User $viewer, User $other, SoulService $souls): ?array
    {
        if (! in_array($other->id, Teammates::of($viewer->id), true)) {
            return null;
        }
        $soul = $souls->for($other);
        if (! ($soul->state['album_public'] ?? false)) {
            return null;
        }
        $a = $this->album($other, $soul);
        // labels only (no secret spoilers, no raw progress)
        foreach ($a['albums'] as &$al) {
            $al['labels'] = array_values(array_filter($al['labels'], fn ($l) => $l['got']));
        }

        return $a;
    }

    /** N-20 · the balance panel: where the economy is leaking. */
    public function balancePanel(): array
    {
        $since = now()->subDays(30);
        $flows = VortexLedger::query()->where('created_at', '>=', $since)
            ->selectRaw('currency, reason, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as earned, SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as spent, COUNT(*) as n')
            ->groupBy('currency', 'reason')->orderByDesc('earned')->limit(60)->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'reason' => preg_replace('/:.*/', '', (string) $r->reason), 'earned' => (int) $r->earned, 'spent' => (int) $r->spent, 'n' => (int) $r->n])
            ->groupBy(fn ($r) => $r['currency'].'|'.$r['reason'])
            ->map(fn ($g) => ['currency' => $g[0]['currency'], 'reason' => $g[0]['reason'], 'earned' => $g->sum('earned'), 'spent' => $g->sum('spent'), 'n' => $g->sum('n')])
            ->values()->all();
        $items = [];
        $souls = VortexSoul::query()->limit(2000)->get(['state']);
        foreach ($souls as $s) {
            foreach ($s->state['inventory'] ?? [] as $i) {
                $items[$i] = ($items[$i] ?? 0) + 1;
            }
        }
        arsort($items);

        return [
            'flows' => $flows,
            'souls' => $souls->count(),
            'items_top' => array_slice($items, 0, 15, true),
            'items_rare' => array_slice(array_reverse($items, true), 0, 10, true),
        ];
    }
}
