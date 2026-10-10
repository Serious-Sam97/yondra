<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\VortexMemory;
use App\Infrastructure\Models\VortexSoul;
use Carbon\CarbonImmutable;

/**
 * LADO L · THE SERIES. Decides which episode (config/vortex_episodes.php) is
 * due for this user — the main line in order, at most one a week; calendar
 * specials on their day; good filler when the main line is waiting on you —
 * and applies what a watched episode leaves behind (effects, remembered
 * choices). The client only stages what this hands it, and can only mark as
 * seen the episode that is actually due.
 */
final class StoryService
{
    public function catalog(): array
    {
        return config('vortex_episodes', []);
    }

    /** The episode due right now, or null. */
    public function due(VortexSoul $soul, ?CarbonImmutable $now = null): ?array
    {
        $s = $soul->state ?? [];
        $story = $this->story($s);
        $local = ($now ?? CarbonImmutable::now())->setTimezone($s['tz'] ?? 'UTC');
        $since = $this->daysSince($story['last_at'] ?? ($s['born'] ?? null), $local);
        $cat = $this->catalog();

        // L-08 · calendar specials win on their day (once a year each)
        foreach ($cat as $id => $ep) {
            if (isset($ep['date']) && $this->isDay($ep['date'], $local)
                && ($story['dated'][$id] ?? null) !== $local->format('Y')) {
                return $this->public($id, $ep);
            }
        }

        foreach ($cat as $id => $ep) {
            if (isset($ep['date']) || ($ep['filler'] ?? false) || in_array($id, $story['seen'], true)) {
                continue;
            }
            if (isset($ep['ending']) && $ep['ending'] !== ($s['ending'] ?? null)) {
                continue;
            }
            if (isset($ep['after']) && ! in_array($ep['after'], $story['seen'], true)) {
                continue;
            }
            if (! $this->met($ep, $soul)) {
                continue; // this one waits on you, not on time
            }
            if ($since >= (int) ($ep['days'] ?? 7)) {
                return $this->public($id, $ep);
            }

            return null; // the next one is coming; the weekly rhythm holds
        }

        // L-07 · nothing on the main line can play: a good filler, once a week
        if ($since >= 7) {
            foreach ($cat as $id => $ep) {
                if (($ep['filler'] ?? false) && ! in_array($id, $story['seen'], true)) {
                    return $this->public($id, $ep);
                }
            }
        }

        return null;
    }

    /**
     * The client watched it. Only the due episode counts; choices are checked
     * against the script.
     *
     * @param  array<string,string>  $choices
     */
    public function seen(VortexSoul $soul, string $id, array $choices): array
    {
        $due = $this->due($soul);
        if ($due === null || $due['id'] !== $id) {
            return ['ok' => false, 'reason' => 'not on tonight'];
        }
        $ep = $this->catalog()[$id];
        $s = $soul->state;
        $story = $this->story($s);
        $local = CarbonImmutable::now($s['tz'] ?? 'UTC');

        $valid = [];
        foreach ($ep['steps'] as $step) {
            if ($step[0] !== 'choice' || ! isset($choices[$step[1]])) {
                continue;
            }
            foreach ($step[3] as [$optId, $label]) {
                if ($optId === $choices[$step[1]]) {
                    $valid[$step[1]] = $optId;
                    // L-11 · he remembers what you chose (it lands in the dossier)
                    VortexMemory::create([
                        'user_id' => $soul->user_id,
                        'category' => 'story',
                        'fact' => mb_substr('in "'.$ep['title'].'" they chose: '.$label, 0, 240),
                        'source' => 'story',
                    ]);
                }
            }
        }
        $story['choices'] = [...$story['choices'], ...$valid];

        if (isset($ep['date'])) {
            $story['dated'][$id] = $local->format('Y');
        } else {
            $story['seen'][] = $id;
            $story['last_at'] = now()->toIso8601String();
            if (! ($ep['filler'] ?? false)) {
                $story['season'] = $ep['season'];
                $story['episode'] = $ep['n'];
            }
        }
        $s['story'] = $story;
        if ($id === 's1e1' && ! in_array('relic-first-episode', $s['inventory'] ?? [], true)) {
            $s['inventory'][] = 'relic-first-episode'; // N-17
        }

        $fx = $ep['effects'] ?? [];
        if (isset($fx['cor'])) {
            $s['corruption'] = max(0, min(100, (float) ($s['corruption'] ?? 0) + $fx['cor']));
        }
        if (isset($fx['scar']) && ! in_array($fx['scar'], $s['scars'] ?? [], true)) {
            $s['scars'][] = $fx['scar'];
        }
        $soul->state = $s;
        if (isset($fx['rel'])) {
            $soul->relation = max(-100, min(100, (int) $soul->relation + (int) $fx['rel']));
        }
        $soul->save();
        if (isset($fx['grant'])) {
            app(FragmentService::class)->grant($soul, $fx['grant']);
        }

        return ['ok' => true, 'choices' => $valid];
    }

    /** L-14 · the videotape library: everything you've watched, to rewatch. */
    public function library(VortexSoul $soul): array
    {
        $story = $this->story($soul->state ?? []);
        $cat = $this->catalog();
        $ids = [...$story['seen'], ...array_keys($story['dated'])];

        return collect($ids)->unique()->filter(fn ($id) => isset($cat[$id]))
            ->map(fn ($id) => [...$this->public($id, $cat[$id]), 'chosen' => $this->chosenIn($cat[$id], $story['choices'])])
            ->values()->all();
    }

    /** Dev: any episode, for the lab. */
    public function peek(string $id): ?array
    {
        $ep = $this->catalog()[$id] ?? null;

        return $ep ? $this->public($id, $ep) : null;
    }

    private function public(string $id, array $ep): array
    {
        return [
            'id' => $id,
            'season' => $ep['season'],
            'n' => $ep['n'],
            'title' => $ep['title'],
            'recap' => $ep['recap'] ?? '',
            'steps' => $ep['steps'],
            'credits' => $ep['credits'] ?? '',
            'teaser' => $ep['teaser'] ?? '',
            'finale' => (bool) ($ep['finale'] ?? false),
            'special' => isset($ep['date']),
        ];
    }

    private function chosenIn(array $ep, array $choices): array
    {
        $out = [];
        foreach ($ep['steps'] as $step) {
            if ($step[0] === 'choice' && isset($choices[$step[1]])) {
                $out[$step[1]] = $choices[$step[1]];
            }
        }

        return $out;
    }

    private function met(array $ep, VortexSoul $soul): bool
    {
        $owned = $soul->state['fragments'] ?? [];
        if (isset($ep['needs']) && ! in_array($ep['needs'], $owned, true)) {
            return false;
        }
        if (isset($ep['frags']) && count($owned) < $ep['frags']) {
            return false;
        }

        return ! (isset($ep['rel']) && (int) $soul->relation < $ep['rel']);
    }

    private function story(array $s): array
    {
        return array_replace(
            ['season' => 1, 'episode' => 0, 'seen' => [], 'choices' => [], 'dated' => [], 'last_at' => null],
            $s['story'] ?? [],
        );
    }

    private function daysSince(?string $iso, CarbonImmutable $local): int
    {
        return $iso ? (int) CarbonImmutable::parse($iso)->diffInDays($local, true) : 0;
    }

    private function isDay(string $date, CarbonImmutable $d): bool
    {
        return match ($date) {
            'fri13' => $d->isFriday() && (int) $d->format('j') === 13,
            'equinox' => in_array($d->format('m-d'), ['03-20', '09-22'], true),
            default => $d->format('m-d') === $date,
        };
    }
}
