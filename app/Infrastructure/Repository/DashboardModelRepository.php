<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardLink;
use App\Infrastructure\Models\Sprint;
use App\Services\ProjectService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only aggregate that powers the /dashboard "command center" home base.
 * One payload assembled from a handful of grouped queries so the frontend does
 * a single round trip instead of N per-board calls.
 */
class DashboardModelRepository
{
    public function index(): array
    {
        $userId = (int) Auth::id();
        $today  = Carbon::today();

        // Every board the user can see: owns it, is shared onto it, or owns the
        // parent project. Mirrors Board::isAccessibleBy()'s three branches.
        $boardIds = Board::whereNull('archived_at')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereHas('sharedWith', fn ($s) => $s->where('users.id', $userId))
                    ->orWhereHas('project', fn ($p) => $p
                        ->where('owner_id', $userId)
                        ->orWhereHas('members', fn ($m) => $m->where('users.id', $userId)->where('role', 'owner')));
            })
            ->pluck('id');

        $crm = $this->crm($boardIds);

        return [
            'vitals'     => $this->vitals($boardIds, $userId, $today, (float) ($crm['open_total'] ?? 0)),
            'queue'      => $this->queue($boardIds, $userId, $today),
            'throughput' => $this->throughput($boardIds, $today),
            'sprint'     => $this->activeSprint($boardIds, $today),
            'crm'        => $crm,
            'prs'        => $this->pullRequests($boardIds),
            'activity'   => $this->activity($boardIds),
            'projects'   => resolve(ProjectService::class)->fetchAll(),
        ];
    }

    /** Cards currently assigned to the user and still open (not done, not archived). */
    private function myOpenCards(Collection $boardIds, int $userId): Collection
    {
        return Card::whereIn('board_id', $boardIds)
            ->where('assigned_user_id', $userId)
            ->whereNull('archived_at')
            ->whereNull('done_at')
            ->with(['section:id,name', 'board:id,name,ticket_prefix'])
            ->get();
    }

    private function vitals(Collection $boardIds, int $userId, Carbon $today, float $pipeline): array
    {
        $open = $this->myOpenCards($boardIds, $userId);

        $done7d = Card::whereIn('board_id', $boardIds)
            ->where('assigned_user_id', $userId)
            ->whereNotNull('done_at')
            ->where('done_at', '>=', $today->copy()->subDays(7))
            ->count();

        return [
            'overdue'     => $open->filter(fn ($c) => $c->due_date && $c->due_date->lt($today))->count(),
            'due_today'   => $open->filter(fn ($c) => $c->due_date && $c->due_date->isSameDay($today))->count(),
            'in_progress' => $open->count(),
            'done_7d'     => $done7d,
            'pipeline'    => round($pipeline, 2),
        ];
    }

    /** "Needs you" queue: my open cards grouped overdue -> due today -> high-priority upcoming. */
    private function queue(Collection $boardIds, int $userId, Carbon $today): array
    {
        $open = $this->myOpenCards($boardIds, $userId);

        $overdue = $open->filter(fn ($c) => $c->due_date && $c->due_date->lt($today))
            ->sortBy(fn ($c) => $c->due_date->timestamp);

        $dueToday = $open->filter(fn ($c) => $c->due_date && $c->due_date->isSameDay($today));

        // High priority that isn't already surfaced as overdue/due-today.
        $high = $open->filter(fn ($c) => in_array($c->priority, ['high', 'urgent'], true)
            && ! ($c->due_date && $c->due_date->lte($today)))
            ->sortBy(fn ($c) => $c->due_date?->timestamp ?? PHP_INT_MAX)
            ->take(6);

        return [
            'overdue' => $overdue->map(fn ($c) => $this->mapCard($c))->values(),
            'today'   => $dueToday->map(fn ($c) => $this->mapCard($c))->values(),
            'high'    => $high->map(fn ($c) => $this->mapCard($c))->values(),
        ];
    }

    /** Completed cards per day across the last 14 days (oldest -> newest). */
    private function throughput(Collection $boardIds, Carbon $today): array
    {
        $start   = $today->copy()->subDays(13);
        $buckets = array_fill(0, 14, 0);

        Card::whereIn('board_id', $boardIds)
            ->whereNotNull('done_at')
            ->where('done_at', '>=', $start)
            ->pluck('done_at')
            ->each(function ($doneAt) use (&$buckets, $start) {
                $idx = $start->diffInDays($doneAt->copy()->startOfDay(), false);
                if ($idx >= 0 && $idx < 14) {
                    $buckets[(int) $idx]++;
                }
            });

        return array_values($buckets);
    }

    /** The single most-recent active sprint across visible boards. */
    private function activeSprint(Collection $boardIds, Carbon $today): ?array
    {
        $sprint = Sprint::whereIn('board_id', $boardIds)
            ->where('is_active', true)
            ->orderByDesc('start_date')
            ->orderByDesc('started_at')
            ->first();

        if (! $sprint) {
            return null;
        }

        $committed = (int) $sprint->committed_points;
        $completed = (int) $sprint->completed_points;
        $daysTotal = $sprint->start_date && $sprint->end_date
            ? $sprint->start_date->diffInDays($sprint->end_date) + 1
            : null;
        $daysLeft = $sprint->end_date
            ? max(0, (int) $today->diffInDays($sprint->end_date, false))
            : null;

        return [
            'name'          => $sprint->name,
            'goal'          => $sprint->goal,
            'committed'     => $committed,
            'completed'     => $completed,
            'remaining'     => max(0, $committed - $completed),
            'days_total'    => $daysTotal,
            'days_left'     => $daysLeft,
            'days_elapsed'  => $daysTotal !== null && $daysLeft !== null ? max(0, $daysTotal - $daysLeft) : null,
        ];
    }

    /** Pipeline for CRM-type boards: funnel by stage + SLA-aging deals. Null when no CRM board. */
    private function crm(Collection $boardIds): ?array
    {
        $boards = Board::whereIn('id', $boardIds)
            ->where('type', 'crm')
            ->with('sections:id,board_id,name,order')
            ->get();

        if ($boards->isEmpty()) {
            return null;
        }

        $crmIds = $boards->pluck('id');

        // section_id => ['name','order'] for stage labels + ordering.
        $sectionMeta = [];
        foreach ($boards as $b) {
            foreach ($b->sections as $s) {
                $sectionMeta[$s->id] = ['name' => $s->name, 'order' => $s->order];
            }
        }

        $open = Card::whereIn('board_id', $crmIds)
            ->whereNull('archived_at')
            ->whereNull('done_at')
            ->get();

        // Aggregate open value by stage name (merges same-named stages across boards).
        $stageMap = [];
        foreach ($open as $c) {
            $meta = $sectionMeta[$c->section_id] ?? ['name' => 'Other', 'order' => 999];
            $name = $meta['name'];
            $stageMap[$name] ??= ['name' => $name, 'value' => 0.0, 'count' => 0, 'order' => $meta['order']];
            $stageMap[$name]['value'] += (float) $c->value;
            $stageMap[$name]['count']++;
        }
        $stages = collect($stageMap)
            ->sortBy('order')
            ->map(fn ($s) => ['name' => $s['name'], 'value' => round($s['value'], 2), 'count' => $s['count']])
            ->values();

        $wonMtd = (float) Card::whereIn('board_id', $crmIds)
            ->whereNotNull('done_at')
            ->where('done_at', '>=', Carbon::now()->startOfMonth())
            ->sum('value');

        $aging = $open->filter(fn ($c) => $c->section_entered_at !== null)
            ->sortBy(fn ($c) => $c->section_entered_at->timestamp)
            ->take(6)
            ->map(fn ($c) => [
                'id'        => $c->id,
                'board_id'  => $c->board_id,
                'name'      => $c->name,
                'stage'     => $sectionMeta[$c->section_id]['name'] ?? null,
                'value'     => $c->value !== null ? (float) $c->value : null,
                'days_idle' => (int) $c->section_entered_at->startOfDay()->diffInDays(Carbon::today()),
            ])
            ->values();

        return [
            'currency'   => $boards->first()->currency ?? 'USD',
            'open_total' => round((float) $open->sum(fn ($c) => (float) $c->value), 2),
            'won_mtd'    => round($wonMtd, 2),
            'stages'     => $stages,
            'aging'      => $aging,
        ];
    }

    /** Open pull-request links across visible boards. */
    private function pullRequests(Collection $boardIds): Collection
    {
        return CardLink::whereIn('board_id', $boardIds)
            ->where('type', 'pr')
            ->where('state', 'open')
            ->orderByDesc('last_synced_at')
            ->limit(10)
            ->get()
            ->map(fn ($l) => [
                'title'        => $l->title,
                'number'       => $l->number,
                'state'        => $l->state,
                'checks_state' => $l->checks_state,
                'url'          => $l->html_url ?? $l->url,
            ]);
    }

    /** Recent activity across visible boards (the live terminal feed). */
    private function activity(Collection $boardIds): Collection
    {
        return BoardActivity::whereIn('board_id', $boardIds)
            ->with('user:id,name')
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn ($a) => [
                'id'          => $a->id,
                'board_id'    => $a->board_id,
                'type'        => $a->type,
                'actor'       => $a->user?->name,
                'description' => $a->description,
                'created_at'  => $a->created_at,
            ]);
    }

    private function mapCard(Card $c): array
    {
        return [
            'id'           => $c->id,
            'name'         => $c->name,
            'board_id'     => $c->board_id,
            'board_name'   => $c->board?->name,
            'section'      => $c->section?->name,
            'priority'     => $c->priority,
            'due_date'     => $c->due_date?->toDateString(),
            'story_points' => $c->story_points,
            'value'        => $c->value !== null ? (float) $c->value : null,
            'ticket_key'   => $this->ticketKey($c->board?->ticket_prefix, $c->ticket_number),
        ];
    }

    /** Mirrors CardModelRepository::composeTicketKey — "YON-42" / "#42" / "". */
    private function ticketKey(?string $prefix, ?int $number): string
    {
        if ($number === null) {
            return '';
        }

        return $prefix ? "{$prefix}-{$number}" : "#{$number}";
    }
}
