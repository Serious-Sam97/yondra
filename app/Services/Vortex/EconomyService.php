<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexLedger;
use App\Infrastructure\Models\VortexSoul;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * LADO N · THE ECONOMY. Three currencies, all on a server ledger
 * (vortex_ledger) so every coin can be audited:
 *   tokens  — games, bets, catching his lies
 *   minutes — real active time (≤ 240/day) and finished cards (≤ 20/day, only
 *             cards older than an hour, each card once, ever)
 *   echoes  — lore: fragments, secret rooms, the dark
 * Nothing pays for fake work: moving a card back and forth doesn't count;
 * archiving and restoring doesn't count. Also: the shops, equipping, crafting,
 * the daily offering, relics, his level and skill tree, collections.
 */
final class EconomyService
{
    public const CURRENCIES = ['tokens', 'minutes', 'echoes'];

    /** reason => [currency, daily cap in that currency] */
    public const CAPS = [
        'game' => ['tokens', 40],
        'bet' => ['tokens', 20],
        'caught_lie' => ['tokens', 6],
        'daily' => ['tokens', 30],
        'active' => ['minutes', 240],
        'card_done' => ['minutes', 100],
        'fragment' => ['echoes', 30],
        'secret_room' => ['echoes', 5],
        'dark' => ['echoes', 3],
        'collection' => ['echoes', 50],
    ];

    /** N-12 · recipes: inputs (all consumed) => output */
    public const RECIPES = [
        'lantern' => ['in' => ['bulb', 'blank-tape'], 'out' => 'craft-lantern'],
        'amulet' => ['in' => ['magnet', 'broken-pencil'], 'out' => 'craft-amulet'],
        'memory' => ['in' => ['cha'], 'echoes' => 3, 'out' => 'craft-memory'],
    ];

    /** N-09 · the skill tree: three branches of five, one point every five levels. */
    public const SKILLS = [
        'genius' => ['tinkerer', 'quick hands', 'second opinion', 'patent pending', 'mad genius'],
        'chaos' => ['prankster', 'poltergeist', 'rare static', 'bad influence', 'agent of chaos'],
        'soul' => ['good listener', 'lucid dreams', 'old friend', 'tape memory', 'kindred'],
    ];

    /** N-11 · sets that pay out once when complete. */
    public const COLLECTIONS = [
        'tarot' => ['name' => 'the 22 cards of the tarot', 'size' => 22, 'bonus' => 20],
        'radio' => ['name' => 'the 12 tapes of the radio', 'size' => 12, 'bonus' => 15],
        'eyes' => ['name' => 'the eyes of the splicer', 'size' => 8, 'bonus' => 10],
        'costumes' => ['name' => 'the wardrobe', 'size' => 36, 'bonus' => 25],
    ];

    public function catalog(): array
    {
        $below = collect(BelowService::ITEMS)->map(fn ($desc, $id) => [
            'name' => str_replace('-', ' ', $id), 'cat' => 'world', 'rarity' => in_array($id, BelowService::RARE, true) ? 'rare' : 'common',
            'desc' => $desc, 'vx' => null, 'price' => null, 'shop' => null,
        ])->all();

        return [...$below, ...config('vortex_items', [])];
    }

    /** @return array{tokens:int, minutes:int, echoes:int} */
    public function balance(int $userId): array
    {
        $sums = VortexLedger::where('user_id', $userId)->groupBy('currency')
            ->select('currency', DB::raw('SUM(amount) as total'))->pluck('total', 'currency');

        return array_combine(self::CURRENCIES, array_map(fn ($c) => (int) ($sums[$c] ?? 0), self::CURRENCIES));
    }

    /** Earn, clamped to the reason's daily cap (and boosted by skills). Returns what was actually credited. */
    public function earn(VortexSoul $soul, string $reason, int $amount): int
    {
        [$currency, $cap] = self::CAPS[$reason] ?? [null, 0];
        if ($currency === null || $amount <= 0) {
            return 0;
        }
        $skills = $soul->state['skills'] ?? [];
        if ($currency === 'tokens' && in_array('genius:1', $skills, true)) {
            $amount = (int) ceil($amount * 1.1);
        }
        if ($currency === 'echoes' && in_array('soul:2', $skills, true)) {
            $amount++;
        }
        $today = CarbonImmutable::now($soul->state['tz'] ?? 'UTC')->startOfDay()->utc();
        $got = (int) VortexLedger::where('user_id', $soul->user_id)->where('reason', $reason)
            ->where('amount', '>', 0)->where('created_at', '>=', $today)->sum('amount');
        $credit = max(0, min($amount, $cap - $got));
        if ($credit > 0) {
            VortexLedger::create(['user_id' => $soul->user_id, 'currency' => $currency, 'amount' => $credit, 'reason' => $reason]);
        }

        return $credit;
    }

    public function spend(int $userId, string $currency, int $amount, string $reason): bool
    {
        if ($amount <= 0) {
            return true;
        }
        if (($this->balance($userId)[$currency] ?? 0) < $amount) {
            return false;
        }
        VortexLedger::create(['user_id' => $userId, 'currency' => $currency, 'amount' => -$amount, 'reason' => mb_substr($reason, 0, 48)]);

        return true;
    }

    /** One minute of real, active use (the client pings while you work). */
    public function tick(VortexSoul $soul): int
    {
        $s = $soul->state;
        $last = isset($s['econ_tick']) ? CarbonImmutable::parse($s['econ_tick']) : null;
        if ($last && $last->diffInSeconds(now(), true) < 50) {
            return 0;
        }
        $s['econ_tick'] = now()->toIso8601String();
        $soul->state = $s;
        $soul->save();

        return $this->earn($soul, 'active', 1);
    }

    /** Finished cards pay minutes: each card once, only if it lived at least an hour. */
    public function settleCards(User $user, VortexSoul $soul): int
    {
        $s = $soul->state;
        $paid = $s['econ_cards'] ?? [];
        $cards = Card::query()->whereNotNull('done_at')->where('done_at', '>=', now()->subDay())
            ->whereNull('archived_at')
            ->whereHas('board', fn ($q) => $q->where('user_id', $user->id)
                ->orWhereIn('id', DB::table('board_shares')->where('user_id', $user->id)->select('board_id')))
            ->whereNotIn('id', $paid)->get(['id', 'created_at', 'done_at']);
        $total = 0;
        foreach ($cards as $c) {
            if ($c->created_at && $c->done_at && $c->created_at->diffInMinutes($c->done_at, true) >= 60) {
                $total += $this->earn($soul, 'card_done', 5);
            }
            $paid[] = $c->id; // seen: never paid twice, even if it goes back and forth
        }
        $s = $soul->fresh()->state;
        $s['econ_cards'] = array_slice(array_values(array_unique($paid)), -500);
        $soul->state = $s;
        $soul->save();

        return $total;
    }

    /** What a shop sells right now. */
    public function stock(VortexSoul $soul, string $shop): array
    {
        $s = $soul->state ?? [];
        $local = CarbonImmutable::now($s['tz'] ?? 'UTC');
        if ($shop === 'black' && (int) $local->format('G') !== 3) {
            return []; // the hooded stall only opens at the dead hour
        }
        $items = collect($this->catalog())->filter(fn ($i) => ($i['shop'] ?? null) === $shop && $i['price'] !== null)
            ->filter(fn ($i) => $this->inSeason($i, $soul, $local));
        if ($shop === 'counter') {
            // N-04 · the counter rotates weekly: seasonal always, plus eight others
            $seed = crc32($local->format('o-W'));
            $seasonal = $items->filter(fn ($i) => isset($i['season']));
            $items = $seasonal->merge($items->reject(fn ($i) => isset($i['season']))
                ->sortBy(fn ($i, $id) => crc32($id.$seed))->take(8));
        }

        return $items->map(fn ($i, $id) => ['id' => $id, ...$this->public($i)])->values()->all();
    }

    public function buy(VortexSoul $soul, string $shop, string $id): array
    {
        $item = collect($this->stock($soul, $shop))->firstWhere('id', $id);
        if (! $item) {
            return ['ok' => false, 'reason' => 'not on the shelf.'];
        }
        $inv = $soul->state['inventory'] ?? [];
        if (in_array($item['slot'] ?? null, ['costume', 'eye', 'border', 'voice', 'trail'], true) && in_array($id, $inv, true)) {
            return ['ok' => false, 'reason' => 'you already own that.'];
        }
        [$cur, $amt] = $item['price'];
        if (! $this->spend($soul->user_id, $cur, $amt, 'buy:'.$id)) {
            return ['ok' => false, 'reason' => 'not enough '.$cur.'.'];
        }
        $s = $soul->fresh()->state;
        $s['inventory'] = [...($s['inventory'] ?? []), $id];
        if (isset($this->catalog()[$id]['corruption'])) {
            $s['corruption'] = min(100, ($s['corruption'] ?? 0) + $this->catalog()[$id]['corruption']);
        }
        $soul->state = $s;
        $soul->save();
        $this->collections($soul);

        return ['ok' => true, 'balance' => $this->balance($soul->user_id), 'inventory' => $s['inventory']];
    }

    /** Wear a mod or costume you own (null takes it off). */
    public function equip(VortexSoul $soul, string $slot, ?string $id): bool
    {
        if (! in_array($slot, ['costume', 'eye', 'border', 'voice', 'trail'], true)) {
            return false;
        }
        // S-08 · the costume you designed is a costume too
        $slotOf = $id === 'custom-costume' ? 'costume' : ($this->catalog()[$id]['slot'] ?? null);
        if ($id !== null && (! in_array($id, $soul->state['inventory'] ?? [], true) || $slotOf !== $slot)) {
            return false;
        }
        $s = $soul->state;
        $s['equip'][$slot] = $id;
        $soul->state = $s;
        $soul->save();

        return true;
    }

    public function craft(VortexSoul $soul, string $recipe): array
    {
        $r = self::RECIPES[$recipe] ?? null;
        if (! $r) {
            return ['ok' => false, 'reason' => 'no such recipe.'];
        }
        $inv = $soul->state['inventory'] ?? [];
        foreach ($r['in'] as $need) {
            $i = array_search($need, $inv, true);
            if ($i === false) {
                return ['ok' => false, 'reason' => 'you need: '.implode(' + ', $r['in']).(isset($r['echoes']) ? ' + '.$r['echoes'].' echoes' : '').'.'];
            }
            unset($inv[$i]);
        }
        if (isset($r['echoes']) && ! $this->spend($soul->user_id, 'echoes', $r['echoes'], 'craft:'.$recipe)) {
            return ['ok' => false, 'reason' => 'not enough echoes.'];
        }
        $s = $soul->fresh()->state;
        $s['inventory'] = [...array_values($inv), $r['out']];
        if ($r['out'] === 'craft-amulet') {
            $s['amulet_until'] = now()->addWeek()->toIso8601String();
            $s['proximity'] = max(0, ($s['proximity'] ?? 0) - 20);
        }
        if ($r['out'] === 'craft-memory') {
            $s['dream_lore'] = true;
        }
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'made' => $r['out'], 'inventory' => $s['inventory']];
    }

    /** N-15 · one offering a day at the altar in his nest. Sometimes he gives something back. */
    public function offer(VortexSoul $soul, string $id): array
    {
        $s = $soul->state;
        $day = CarbonImmutable::now($s['tz'] ?? 'UTC')->toDateString();
        if (($s['offered_on'] ?? null) === $day) {
            return ['ok' => false, 'reason' => 'one offering a day. he\'s spoiled enough.'];
        }
        $inv = $s['inventory'] ?? [];
        $i = array_search($id, $inv, true);
        $item = $this->catalog()[$id] ?? null;
        if ($i === false || ! $item || in_array($item['rarity'], ['unique'], true)) {
            return ['ok' => false, 'reason' => 'you can\'t offer that.'];
        }
        unset($inv[$i]);
        $s['offered_on'] = $day;
        $s['needs']['loneliness'] = max(0, ($s['needs']['loneliness'] ?? 50) - 15);
        $s['needs']['ego'] = min(100, ($s['needs']['ego'] ?? 50) + 5);
        $back = null;
        if (random_int(1, 4) === 1) {
            $commons = collect($this->catalog())->filter(fn ($x) => $x['cat'] === 'common')->keys()->all();
            $back = $commons[array_rand($commons)];
            $inv[] = $back;
        }
        $s['inventory'] = array_values($inv);
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'back' => $back, 'inventory' => $s['inventory']];
    }

    /** N-17 · a relic for a moment that mattered (once). */
    public function relic(VortexSoul $soul, string $id): bool
    {
        $inv = $soul->state['inventory'] ?? [];
        if (in_array($id, $inv, true) || ! isset($this->catalog()[$id])) {
            return false;
        }
        $s = $soul->state;
        $s['inventory'] = [...$inv, $id];
        $soul->state = $s;
        $soul->save();

        return true;
    }

    /** N-08 · his level (1–99) from your time together and the story. */
    public function level(VortexSoul $soul): array
    {
        $minutes = (int) VortexLedger::where('user_id', $soul->user_id)->where('currency', 'minutes')->where('amount', '>', 0)->sum('amount');
        $s = $soul->state ?? [];
        $xp = $minutes + 40 * count($s['story']['seen'] ?? []) + 15 * count($s['fragments'] ?? [])
            + 10 * (int) CarbonImmutable::parse($s['born'] ?? now())->diffInDays(now(), true);
        $level = min(99, 1 + (int) floor(sqrt($xp / 12)));
        $next = (int) (12 * $level ** 2);
        $points = intdiv($level, 5) - count($s['skills'] ?? []);

        return ['level' => $level, 'xp' => $xp, 'next' => $next, 'points' => max(0, $points), 'skills' => array_values($s['skills'] ?? [])];
    }

    /** N-09 · solder the next component on a branch. */
    public function learn(VortexSoul $soul, string $branch): array
    {
        if (! isset(self::SKILLS[$branch])) {
            return ['ok' => false, 'reason' => 'no such branch.'];
        }
        $lv = $this->level($soul);
        if ($lv['points'] < 1) {
            return ['ok' => false, 'reason' => 'no points. level up first.'];
        }
        $have = count(array_filter($lv['skills'], fn ($k) => str_starts_with($k, $branch.':')));
        if ($have >= 5) {
            return ['ok' => false, 'reason' => 'that branch is full.'];
        }
        $s = $soul->state;
        $s['skills'][] = $branch.':'.($have + 1);
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'skill' => self::SKILLS[$branch][$have], 'level' => $this->level($soul)];
    }

    /** N-11 · progress on each set; completing one pays its bonus once. */
    public function collections(VortexSoul $soul): array
    {
        $s = $soul->state ?? [];
        $inv = $s['inventory'] ?? [];
        $have = [
            'tarot' => count(array_unique(array_filter($inv, fn ($i) => str_starts_with($i, 'tarot-')))),
            'radio' => count($s['radio_tapes'] ?? []),
            'eyes' => count(array_unique(array_filter($inv, fn ($i) => str_starts_with($i, 'eye-')))),
            'costumes' => count(array_unique(array_filter($inv, fn ($i) => str_starts_with($i, 'costume-')))),
        ];
        $done = $s['collections_done'] ?? [];
        $out = [];
        foreach (self::COLLECTIONS as $id => $c) {
            $complete = $have[$id] >= $c['size'];
            if ($complete && ! in_array($id, $done, true)) {
                $this->earn($soul, 'collection', $c['bonus']);
                $done[] = $id;
                $s = $soul->fresh()->state;
                $s['collections_done'] = $done;
                $soul->state = $s;
                $soul->save();
            }
            $out[] = ['id' => $id, 'name' => $c['name'], 'have' => min($have[$id], $c['size']), 'size' => $c['size'], 'complete' => $complete];
        }

        return $out;
    }

    /** Everything the case (N-01) shows. */
    public function view(User $user, VortexSoul $soul): array
    {
        $cat = $this->catalog();
        $inv = collect($soul->state['inventory'] ?? [])->countBy()
            ->map(fn ($n, $id) => isset($cat[$id]) ? ['id' => $id, 'n' => $n, ...$this->public($cat[$id])] : null)
            ->filter()->values()->all();

        return [
            'balance' => $this->balance($user->id),
            'inventory' => $inv,
            'equip' => $soul->state['equip'] ?? [],
            'level' => $this->level($soul),
            'collections' => $this->collections($soul),
            'recipes' => collect(self::RECIPES)->map(fn ($r, $id) => ['id' => $id, 'in' => $r['in'], 'echoes' => $r['echoes'] ?? 0, 'out' => $r['out'], 'out_name' => $cat[$r['out']]['name'] ?? $r['out']])->values()->all(),
            'skills_tree' => self::SKILLS,
            'offered_today' => ($soul->state['offered_on'] ?? null) === CarbonImmutable::now($soul->state['tz'] ?? 'UTC')->toDateString(),
        ];
    }

    private function public(array $i): array
    {
        return array_intersect_key($i, array_flip(['name', 'cat', 'rarity', 'desc', 'vx', 'price', 'slot', 'color', 'effect', 'corruption']));
    }

    private function inSeason(array $item, VortexSoul $soul, CarbonImmutable $local): bool
    {
        $season = $item['season'] ?? null;
        if ($season === null) {
            return true;
        }
        if ($season === 'birthday') {
            $born = CarbonImmutable::parse($soul->state['born'] ?? now())->setYear((int) $local->format('Y'));

            return abs($born->diffInDays($local, false)) <= 3;
        }
        $md = $local->format('m-d');

        return $md >= $season[0] && $md <= $season[1];
    }
}
