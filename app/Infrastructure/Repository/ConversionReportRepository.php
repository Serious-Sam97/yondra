<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Repository\Concerns\ResolvesCrmBoards;
use Illuminate\Support\Carbon;

/**
 * Read-only monthly conversion-rate report (YON-65). For each month it counts
 * the cards that reached the Won column that month (numerator, bucketed by
 * `done_at`, exactly like the revenue report's "won" count) and divides by the
 * total number of cards currently on the user's CRM boards (denominator — a
 * live, all-time snapshot, so every month shares the same total). The result is
 * "his monthly conversion rate": share of the whole pipeline closed each month.
 */
class ConversionReportRepository
{
    use ResolvesCrmBoards;

    /**
     * @param  string  $from  inclusive start month, "YYYY-MM"
     * @param  string  $to  inclusive end month, "YYYY-MM"
     */
    public function conversion(string $from, string $to): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $from.'-01')->startOfMonth();
        $end = Carbon::createFromFormat('Y-m-d', $to.'-01')->endOfMonth();

        $crmBoards = $this->accessibleCrmBoards();
        $months = $this->monthSkeleton($start, $end);

        if ($crmBoards->isEmpty()) {
            return [
                'from' => $start->format('Y-m'),
                'to' => $end->format('Y-m'),
                'has_crm' => false,
                'months' => $this->flattenMonths($months, 0),
                'total_won' => 0,
                'total_cards' => 0,
                'conversion_rate' => 0.0,
            ];
        }

        $boardIds = $crmBoards->pluck('id');

        // Denominator: every non-archived card currently on the CRM boards. It's a
        // point-in-time snapshot (the same figure for every month in the range).
        $totalCards = Card::whereIn('board_id', $boardIds)
            ->whereNull('archived_at')
            ->count();

        // Numerator: cards whose reached-Won stamp (`done_at`) lands in the month.
        $won = Card::whereIn('board_id', $boardIds)
            ->whereNotNull('done_at')
            ->where('done_at', '>=', $start)
            ->where('done_at', '<=', $end)
            ->get(['id', 'done_at']);

        foreach ($won as $c) {
            $key = $c->done_at->format('Y-m');
            if (! isset($months[$key])) {
                continue; // defensive: a done_at outside the skeleton
            }
            $months[$key]['won']++;
        }

        return [
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'has_crm' => true,
            'months' => $this->flattenMonths($months, $totalCards),
            'total_won' => $won->count(),
            'total_cards' => $totalCards,
            'conversion_rate' => $totalCards > 0
                ? round($won->count() / $totalCards, 4)
                : 0.0,
        ];
    }

    /**
     * Zeroed month buckets keyed by "YYYY-MM", oldest -> newest, so the chart
     * has no gaps.
     *
     * @return array<string,array{month:string,won:int}>
     */
    private function monthSkeleton(Carbon $start, Carbon $end): array
    {
        $months = [];
        $cursor = $start->copy()->startOfMonth();
        $last = $end->copy()->startOfMonth();
        while ($cursor <= $last) {
            $months[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'won' => 0,
            ];
            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * Attach the (constant) total and the per-month rate = won / total, as a
     * 0..1 fraction the frontend renders as a percentage.
     */
    private function flattenMonths(array $months, int $totalCards): array
    {
        return array_values(array_map(fn ($m) => [
            'month' => $m['month'],
            'won' => $m['won'],
            'total' => $totalCards,
            'rate' => $totalCards > 0 ? round($m['won'] / $totalCards, 4) : 0.0,
        ], $months));
    }
}
