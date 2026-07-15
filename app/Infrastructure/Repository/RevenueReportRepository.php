<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only monthly revenue report (YON-64). Generalises the dashboard's
 * single-month `won_mtd` into a period-selectable series: revenue (SUM of card
 * value) plus approved-quote and distinct-client counts, bucketed by month of
 * `done_at`, across every CRM board the user can see. An "approved quote" is a
 * CRM card marked done; a "client" is that card's contact.
 */
class RevenueReportRepository
{
    /**
     * @param  string  $from  inclusive start month, "YYYY-MM"
     * @param  string  $to  inclusive end month, "YYYY-MM"
     */
    public function revenue(string $from, string $to): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $from.'-01')->startOfMonth();
        $end = Carbon::createFromFormat('Y-m-d', $to.'-01')->endOfMonth();

        $crmBoards = $this->accessibleCrmBoards();

        // Contiguous month skeleton so the bar chart has no gaps — empty months
        // render as zero bars (mirrors how throughput() zero-fills its days).
        $months = $this->monthSkeleton($start, $end);

        if ($crmBoards->isEmpty()) {
            return [
                'currency' => null,
                'from' => $start->format('Y-m'),
                'to' => $end->format('Y-m'),
                'months' => $this->flattenMonths($months),
                'total_revenue' => 0.0,
                'total_count' => 0,
                'total_clients' => 0,
            ];
        }

        $won = Card::whereIn('board_id', $crmBoards->pluck('id'))
            ->whereNotNull('done_at')
            ->where('done_at', '>=', $start)
            ->where('done_at', '<=', $end)
            ->get(['id', 'value', 'done_at', 'contact_id']);

        // Distinct clients across the whole range (contact_id, nulls ignored).
        $rangeClients = [];

        foreach ($won as $c) {
            $key = $c->done_at->format('Y-m');
            if (! isset($months[$key])) {
                continue; // defensive: a done_at that lands outside the skeleton
            }
            // A won card with no value still counts as an approved quote (0 revenue).
            $months[$key]['revenue'] += (float) $c->value;
            $months[$key]['count']++;
            if ($c->contact_id !== null) {
                $months[$key]['clients'][$c->contact_id] = true;
                $rangeClients[$c->contact_id] = true;
            }
        }

        return [
            'currency' => $crmBoards->first()->currency ?? 'USD',
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'months' => $this->flattenMonths($months),
            'total_revenue' => round((float) $won->sum(fn ($c) => (float) $c->value), 2),
            'total_count' => $won->count(),
            'total_clients' => count($rangeClients),
        ];
    }

    /**
     * Zeroed month buckets keyed by "YYYY-MM", oldest -> newest. `clients` is a
     * set (contact_id => true) collapsed to a count by flattenMonths().
     *
     * @return array<string,array{month:string,revenue:float,count:int,clients:array<int,bool>}>
     */
    private function monthSkeleton(Carbon $start, Carbon $end): array
    {
        $months = [];
        $cursor = $start->copy()->startOfMonth();
        $last = $end->copy()->startOfMonth();
        while ($cursor <= $last) {
            $months[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'revenue' => 0.0,
                'count' => 0,
                'clients' => [],
            ];
            $cursor->addMonth();
        }

        return $months;
    }

    /** Collapse the per-month client set into a count and round money. */
    private function flattenMonths(array $months): array
    {
        return array_values(array_map(fn ($m) => [
            'month' => $m['month'],
            'revenue' => round($m['revenue'], 2),
            'count' => $m['count'],
            'clients' => count($m['clients']),
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
