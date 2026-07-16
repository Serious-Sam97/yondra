<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only monthly loss report (YON-66). The mirror of the revenue report:
 * lost deals (cards with `lost_at` set) bucketed by month, plus a breakdown by
 * loss reason — the headline, since the point of the feature is understanding
 * WHY deals are lost. Scoped to every CRM board the user can see.
 */
class LossReportRepository
{
    /**
     * @param  string  $from  inclusive start month, "YYYY-MM"
     * @param  string  $to  inclusive end month, "YYYY-MM"
     */
    public function loss(string $from, string $to): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $from.'-01')->startOfMonth();
        $end = Carbon::createFromFormat('Y-m-d', $to.'-01')->endOfMonth();

        $crmBoards = $this->accessibleCrmBoards();
        $months = $this->monthSkeleton($start, $end);

        if ($crmBoards->isEmpty()) {
            return [
                'currency' => null,
                'from' => $start->format('Y-m'),
                'to' => $end->format('Y-m'),
                'months' => $this->flattenMonths($months),
                'reasons' => [],
                'total_lost_value' => 0.0,
                'total_count' => 0,
            ];
        }

        $lost = Card::whereIn('board_id', $crmBoards->pluck('id'))
            ->whereNotNull('lost_at')
            ->where('lost_at', '>=', $start)
            ->where('lost_at', '<=', $end)
            ->get(['id', 'value', 'lost_at', 'loss_reason', 'contact_id']);

        // Breakdown by reason across the whole range: count + lost pipeline value.
        $reasons = [];
        foreach ($lost as $c) {
            $key = $c->lost_at->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['lost_value'] += (float) $c->value;
                $months[$key]['count']++;
            }
            $label = $c->loss_reason !== null && $c->loss_reason !== '' ? $c->loss_reason : 'Unspecified';
            $reasons[$label] ??= ['reason' => $label, 'count' => 0, 'value' => 0.0];
            $reasons[$label]['count']++;
            $reasons[$label]['value'] += (float) $c->value;
        }

        // Most-frequent reason first (value breaks ties) — the report leads with this.
        $reasonRows = array_values($reasons);
        usort($reasonRows, fn ($a, $b) => $b['count'] <=> $a['count'] ?: $b['value'] <=> $a['value']);
        $reasonRows = array_map(fn ($r) => [
            'reason' => $r['reason'],
            'count' => $r['count'],
            'value' => round($r['value'], 2),
        ], $reasonRows);

        return [
            'currency' => $crmBoards->first()->currency ?? 'USD',
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'months' => $this->flattenMonths($months),
            'reasons' => $reasonRows,
            'total_lost_value' => round((float) $lost->sum(fn ($c) => (float) $c->value), 2),
            'total_count' => $lost->count(),
        ];
    }

    /**
     * Zeroed month buckets keyed by "YYYY-MM", oldest -> newest.
     *
     * @return array<string,array{month:string,lost_value:float,count:int}>
     */
    private function monthSkeleton(Carbon $start, Carbon $end): array
    {
        $months = [];
        $cursor = $start->copy()->startOfMonth();
        $last = $end->copy()->startOfMonth();
        while ($cursor <= $last) {
            $months[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'lost_value' => 0.0,
                'count' => 0,
            ];
            $cursor->addMonth();
        }

        return $months;
    }

    /** Round money and re-index as a list. */
    private function flattenMonths(array $months): array
    {
        return array_values(array_map(fn ($m) => [
            'month' => $m['month'],
            'lost_value' => round($m['lost_value'], 2),
            'count' => $m['count'],
        ], $months));
    }

    /** CRM boards the current user can see (owns / shared / project-owner). */
    private function accessibleCrmBoards(): Collection
    {
        $userId = (int) Auth::id();

        return Board::whereNull('archived_at')
            ->where('type', 'crm')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereHas('sharedWith', fn ($s) => $s->where('users.id', $userId))
                    ->orWhereHas('project', fn ($p) => $p
                        ->where('owner_id', $userId)
                        ->orWhereHas('members', fn ($m) => $m->where('users.id', $userId)->where('role', 'owner')));
            })
            ->get(['id', 'currency']);
    }
}
