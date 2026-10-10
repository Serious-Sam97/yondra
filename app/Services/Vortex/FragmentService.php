<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexSoul;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * K · THE FRAGMENTS (design/vortex-mk5/lados/K-misterios.md). The ARG's 64
 * pieces of lore (config/vortex_fragments.php). Every claim is validated HERE
 * — codes are compared by hash, time windows in the user's timezone,
 * prerequisites against what they own, multiplayer ones against a teammate's
 * claim — so nobody can DevTools their way to the end. Texts only leave the
 * server for fragments the user owns.
 */
final class FragmentService
{
    /** Fragments that arrive on their own, one per day (K · rule 3). */
    private const DRIP = ['F11', 'F22', 'F47', 'F48', 'F49'];

    public function catalog(): array
    {
        return config('vortex_fragments', []);
    }

    /** @return list<string> */
    public function owned(VortexSoul $soul): array
    {
        return array_values($soul->state['fragments'] ?? []);
    }

    /**
     * Try to claim a fragment.
     *
     * @return array{ok:bool, reason?:string, fragment?:array<string,mixed>}
     */
    public function claim(User $user, VortexSoul $soul, string $id, ?string $proof): array
    {
        $others = null;
        $f = $this->catalog()[$id] ?? null;
        if ($f === null) {
            return ['ok' => false, 'reason' => 'no such fragment'];
        }
        if (in_array($id, $this->owned($soul), true)) {
            return ['ok' => true, 'fragment' => $this->public($id, $f, $soul)];
        }
        $owned = $this->owned($soul);
        foreach ($f['after'] ?? [] as $req) {
            if (! in_array($req, $owned, true)) {
                return ['ok' => false, 'reason' => 'not yet'];
            }
        }
        $tz = $soul->state['tz'] ?? 'UTC';
        $local = CarbonImmutable::now($tz);
        $ok = match ($f['claim']) {
            'code' => $proof !== null && hash_equals($f['secret'], sha1(mb_strtolower(trim($proof)))),
            'time' => $this->inWindow($local, $f['window'] ?? ''),
            'date' => $local->format('m-d') === ($f['date'] ?? ''),
            'after' => true,
            'presence' => ($others = $this->presence($id, $user->id)) !== null,
            default => false, // passive fragments are granted by the server, never claimed
        };
        if (! $ok) {
            return ['ok' => false, 'reason' => $f['claim'] === 'presence' ? 'waiting for someone else' : 'nothing happens'];
        }
        $this->grant($soul, $id);
        // the teammates who were waiting on the other side get it too
        foreach ($others ?? [] as $otherId) {
            $other = VortexSoul::where('user_id', $otherId)->first();
            if ($other) {
                $this->grant($other, $id);
            }
        }

        return ['ok' => true, 'fragment' => $this->public($id, $f, $soul)];
    }

    /** Give a fragment (server-side triggers). Returns true when it's new. */
    public function grant(VortexSoul $soul, string $id): bool
    {
        if (! isset($this->catalog()[$id]) || in_array($id, $this->owned($soul), true)) {
            return false;
        }
        $state = $soul->state;
        $state['fragments'] = [...($state['fragments'] ?? []), $id];
        $state['frag_at'] = now()->toIso8601String();
        $state['corruption'] = min(100, ($state['corruption'] ?? 0) + 2); // knowing has a price (H-01)
        $soul->state = $state;
        $soul->save();
        app(EconomyService::class)->earn($soul, 'fragment', 3); // N · knowing pays in echoes
        app(SocialService::class)->announceDiscovery((int) $soul->user_id, (string) ($this->catalog()[$id]['where'] ?? 'somewhere')); // P-17

        return true;
    }

    /** One passive fragment per local day, on a visit. */
    public function drip(VortexSoul $soul): ?string
    {
        $day = CarbonImmutable::now($soul->state['tz'] ?? 'UTC')->toDateString();
        if (($soul->state['frag_drip_day'] ?? null) === $day) {
            return null;
        }
        $next = collect(self::DRIP)->first(fn ($id) => ! in_array($id, $this->owned($soul), true));
        $state = $soul->state;
        $state['frag_drip_day'] = $day;
        $soul->state = $state;
        $soul->save();
        if ($next !== null) {
            $this->grant($soul, $next);
        }

        return $next;
    }

    /** What the dossier of fragments shows: owned texts + a hint for the stuck. */
    public function view(VortexSoul $soul): array
    {
        $cat = $this->catalog();
        $owned = $this->owned($soul);
        $last = isset($soul->state['frag_at']) ? CarbonImmutable::parse($soul->state['frag_at']) : null;
        $stuckDays = $last ? (int) $last->diffInDays(now(), true) : 0;
        $hintLevel = $stuckDays >= 21 ? 2 : ($stuckDays >= 14 ? 1 : ($stuckDays >= 7 ? 0 : -1));
        $next = collect($cat)->filter(fn ($f, $id) => ! in_array($id, $owned, true) && $f['claim'] !== 'passive'
            && collect($f['after'] ?? [])->every(fn ($r) => in_array($r, $owned, true)))
            ->sortBy('layer')->keys()->first();
        $hint = $next !== null && $hintLevel >= 0 ? ($cat[$next]['hints'][$hintLevel] ?? null) : null;

        return [
            'owned' => collect($owned)->map(fn ($id) => $this->public($id, $cat[$id], $soul))->values()->all(),
            'total' => count($cat),
            'hint' => $hint ?: null,
            'hint_where' => $hint ? $cat[$next]['where'] : null,
        ];
    }

    /** The lore he may talk about: texts of what the user has found (last 12). */
    public function loreFor(VortexSoul $soul): array
    {
        $cat = $this->catalog();

        return collect($this->owned($soul))->take(-12)->map(fn ($id) => $cat[$id]['text'] ?? null)->filter()->values()->all();
    }

    /** K-11 · /confess hands over a version of his origin, by how he feels about you. */
    public function confessionFor(VortexSoul $soul): ?string
    {
        $rel = (int) $soul->relation;
        $id = match (true) {
            $rel >= 60 && in_array('F30', $this->owned($soul), true) => 'F44',
            $rel <= -20 => 'F12',
            default => 'F18',
        };

        return $this->grant($soul, $id) ? $id : null;
    }

    /**
     * Any short phrase said to him in chat: does it open a reachable code
     * fragment? (Dream fragments are excluded: they open only by clicking the
     * dream itself.) Also the multiplayer word.
     *
     * @return list<array<string,mixed>> newly granted fragments
     */
    public function guess(User $user, VortexSoul $soul, string $phrase): array
    {
        $phrase = mb_strtolower(trim($phrase));
        if ($phrase === '' || mb_strlen($phrase) > 80) {
            return [];
        }
        if ($phrase === 'together') {
            $r = $this->claim($user, $soul, 'F45', null);

            return $r['ok'] ? [$r['fragment']] : [];
        }
        $hash = sha1($phrase);
        $won = [];
        foreach ($this->catalog() as $id => $f) {
            if ($f['claim'] !== 'code' || ($f['guess'] ?? true) === false || ! hash_equals($f['secret'], $hash)) {
                continue;
            }
            if (in_array($id, $this->owned($soul->fresh()), true)) {
                continue;
            }
            $r = $this->claim($user, $soul->fresh(), $id, $phrase);
            if ($r['ok']) {
                $won[] = $r['fragment'];
            }
        }

        return $won;
    }

    /** K-09 · the CSV row from 1989, for players at least 20 fragments deep (once). */
    public static function exportGhostLine(?User $user): string
    {
        if (! $user) {
            return '';
        }
        $soul = VortexSoul::where('user_id', $user->id)->first();
        if (! $soul || count($soul->state['fragments'] ?? []) < 20 || ($soul->state['csv_ghost_seen'] ?? false)) {
            return '';
        }
        $state = $soul->state;
        $state['csv_ghost_seen'] = true;
        $soul->state = $state;
        $soul->save();

        return "# #0,\"make it remember me\",LAST TAKE,1989-03-13 03:13,M. Vex\n";
    }

    private function public(string $id, array $f, VortexSoul $soul): array
    {
        $text = ((int) ($soul->state['ngplus'] ?? 0)) > 0 ? ($f['text_b'] ?? EndingService::flipText($f['text'])) : $f['text'];

        return ['id' => $id, 'layer' => $f['layer'], 'where' => $f['where'], 'text' => $text];
    }

    private function inWindow(CarbonImmutable $local, string $window): bool
    {
        if (! preg_match('/^(\d\d):(\d\d)-(\d\d):(\d\d)$/', $window, $m)) {
            return false;
        }
        $now = (int) $local->format('G') * 60 + (int) $local->format('i');

        return $now >= ((int) $m[1] * 60 + (int) $m[2]) && $now < ((int) $m[3] * 60 + (int) $m[4]);
    }

    /**
     * Two (or more) teammates claiming the same multiplayer fragment within a
     * minute. Returns the other user ids when enough are present, else null.
     *
     * @return list<int>|null
     */
    private function presence(string $id, int $userId): ?array
    {
        $key = 'vortex:presence:'.$id;
        $waiting = Cache::get($key, []);
        $waiting = array_filter($waiting, fn ($t) => $t > time() - 60);
        $others = array_diff_key($waiting, [$userId => true]);
        $waiting[$userId] = time();
        Cache::put($key, $waiting, 120);
        $need = $id === 'F46' ? 2 : 1;
        if (count($others) < $need) {
            return null;
        }
        Cache::forget($key);

        return array_map('intval', array_keys($others));
    }
}
