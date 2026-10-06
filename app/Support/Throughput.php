<?php

declare(strict_types=1);

namespace App\Support;

use App\Infrastructure\Models\Card;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Completed-cards-per-day series shared by the dashboard and the project page.
 * Zero-filled so the client always gets exactly $days buckets, oldest first,
 * with today in the last slot.
 */
final class Throughput
{
    /** @return list<int> */
    public static function lastDays(Collection $boardIds, Carbon $today, int $days = 14): array
    {
        $start = $today->copy()->subDays($days - 1);
        $buckets = array_fill(0, $days, 0);

        if ($boardIds->isEmpty()) {
            return $buckets;
        }

        Card::whereIn('board_id', $boardIds)
            ->whereNotNull('done_at')
            ->where('done_at', '>=', $start)
            ->pluck('done_at')
            ->each(function ($doneAt) use (&$buckets, $start, $days) {
                $idx = $start->diffInDays($doneAt->copy()->startOfDay(), false);
                if ($idx >= 0 && $idx < $days) {
                    $buckets[(int) $idx]++;
                }
            });

        return array_values($buckets);
    }
}
