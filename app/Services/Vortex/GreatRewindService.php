<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\VortexWorldState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * L-09 · THE GREAT REWIND. One event, for every Yondra user at the same hour.
 * Announced for two weeks by clues; for its hour the whole app slips into the
 * rewound dimension, every Vortex runs, and the community has to save cards
 * (real completions — `done_at` inside the window, counted here, never sent by
 * a client). Hit the goal and the rewind is held off; miss it and the tape
 * loses a season. The result is written to vortex_world_state for good.
 */
final class GreatRewindService
{
    private const KEY = 'great_rewind';

    public function schedule(CarbonImmutable $start, int $minutes, int $goal): array
    {
        $w = VortexWorldState::firstOrNew(['key' => self::KEY]);
        $w->value = [
            'id' => $start->format('Ymd-Hi'),
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->addMinutes($minutes)->toIso8601String(),
            'goal' => $goal,
            'result' => null,
        ];
        $w->save();
        Cache::forget('vortex:gr:saved');

        return $this->view();
    }

    /** @return array{phase:string, id?:string, starts_at?:string, ends_at?:string, goal?:int, saved?:int, result?:?string} */
    public function view(?CarbonImmutable $now = null): array
    {
        $v = VortexWorldState::find(self::KEY)?->value;
        if (! $v) {
            return ['phase' => 'none'];
        }
        $now ??= CarbonImmutable::now();
        $start = CarbonImmutable::parse($v['starts_at']);
        $end = CarbonImmutable::parse($v['ends_at']);
        $phase = match (true) {
            $now->lt($start->subDays(14)) => 'none',
            $now->lt($start) => 'announced',
            $now->lt($end) => 'live',
            $now->lt($end->addDays(7)) => 'over',
            default => 'none',
        };
        if ($phase === 'none') {
            return ['phase' => 'none', 'result' => $v['result'] ?? null];
        }

        return [
            'phase' => $phase,
            'id' => $v['id'],
            'starts_at' => $v['starts_at'],
            'ends_at' => $v['ends_at'],
            'goal' => (int) $v['goal'],
            'saved' => $phase === 'announced' ? 0 : $this->saved($start, $end, $now),
            'result' => $v['result'] ?? ($phase === 'over' ? $this->resolve() : null),
        ];
    }

    /** Cards finished by everyone inside the window. Cached briefly: everyone polls this. */
    public function saved(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now): int
    {
        return (int) Cache::remember('vortex:gr:saved', 20, fn () => Card::query()
            ->whereNotNull('done_at')
            ->whereBetween('done_at', [$start, $now->lt($end) ? $now : $end])
            ->count());
    }

    /** After the hour: held or rewound, written once, forever. */
    public function resolve(): ?string
    {
        $w = VortexWorldState::find(self::KEY);
        $v = $w?->value;
        if (! $v || ($v['result'] ?? null) !== null) {
            return $v['result'] ?? null;
        }
        $start = CarbonImmutable::parse($v['starts_at']);
        $end = CarbonImmutable::parse($v['ends_at']);
        if (CarbonImmutable::now()->lt($end)) {
            return null;
        }
        Cache::forget('vortex:gr:saved');
        $saved = $this->saved($start, $end, $end);
        $v['saved'] = $saved;
        $v['result'] = $saved >= (int) $v['goal'] ? 'held' : 'rewound';
        $w->value = $v;
        $w->save();
        if ($v['result'] === 'rewound') {
            // the tape lost a season, for everyone
            $tape = VortexWorldState::firstOrNew(['key' => 'tape']);
            $tape->value = ['hours' => (int) ($tape->value['hours'] ?? 0) + 1095];
            $tape->save();
        }

        return $v['result'];
    }
}
