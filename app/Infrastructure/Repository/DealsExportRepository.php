<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Repository\Concerns\ResolvesCrmBoards;
use Illuminate\Support\Carbon;

/**
 * Read-only deals export (YON-67). Where the revenue/conversion/loss reports
 * aggregate CRM deals into monthly series, this flattens the same deals into a
 * tabular, exportable ledger — one row per deal — so the pipeline can leave
 * Yondra as a CSV or a printable report (matching/beating the old Pipedrive
 * export). Scoped, like the other reports, to every CRM board the user can see.
 *
 * Status semantics decide both the row set AND which date the period window
 * filters on, so "won deals in July" means done_at ∈ July (the monthly-close
 * workflow: won+paid+delivered → drag to Won → generate report):
 *   - won  → cards with done_at set, windowed by done_at
 *   - lost → cards with lost_at set, windowed by lost_at
 *   - open → cards with neither done_at nor lost_at, windowed by created_at
 *   - all  → every non-archived deal, windowed by (done_at ?? lost_at ?? created_at)
 */
class DealsExportRepository
{
    use ResolvesCrmBoards;

    /**
     * The export's ordered column set. Kept here as the single source of truth
     * so the JSON payload, the CSV header, and the printable report all agree.
     * Roughly mirrors a Pipedrive deal export; the exact column list can be
     * retuned when the reference sample lands.
     *
     * @var array<int,array{key:string,label:string,type:string}>
     */
    public const COLUMNS = [
        ['key' => 'ticket', 'label' => 'Ref', 'type' => 'text'],
        ['key' => 'deal', 'label' => 'Deal', 'type' => 'text'],
        ['key' => 'client', 'label' => 'Client', 'type' => 'text'],
        ['key' => 'board', 'label' => 'Pipeline', 'type' => 'text'],
        ['key' => 'stage', 'label' => 'Stage', 'type' => 'text'],
        ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
        ['key' => 'value', 'label' => 'Value', 'type' => 'money'],
        ['key' => 'paid', 'label' => 'Paid', 'type' => 'money'],
        ['key' => 'currency', 'label' => 'Currency', 'type' => 'text'],
        ['key' => 'owner', 'label' => 'Owner', 'type' => 'text'],
        ['key' => 'priority', 'label' => 'Priority', 'type' => 'text'],
        ['key' => 'tags', 'label' => 'Tags', 'type' => 'text'],
        ['key' => 'created', 'label' => 'Created', 'type' => 'date'],
        ['key' => 'due', 'label' => 'Due', 'type' => 'date'],
        ['key' => 'closed', 'label' => 'Closed', 'type' => 'date'],
        ['key' => 'loss_reason', 'label' => 'Loss reason', 'type' => 'text'],
    ];

    /** @var array<int,string> */
    public const STATUSES = ['all', 'won', 'lost', 'open'];

    /**
     * @param  string  $from  inclusive start month, "YYYY-MM"
     * @param  string  $to  inclusive end month, "YYYY-MM"
     * @param  string  $status  one of self::STATUSES
     * @param  int|null  $boardId  restrict to a single CRM board (still access-checked)
     * @return array{
     *   columns: array<int,array{key:string,label:string,type:string}>,
     *   rows: array<int,array<string,mixed>>,
     *   from: string, to: string, status: string,
     *   currency: string|null, multi_currency: bool,
     *   total_value: float, total_paid: float, count: int,
     *   generated_for: string
     * }
     */
    public function export(string $from, string $to, string $status, ?int $boardId = null): array
    {
        $status = in_array($status, self::STATUSES, true) ? $status : 'all';
        $start = Carbon::createFromFormat('Y-m-d', $from.'-01')->startOfMonth();
        $end = Carbon::createFromFormat('Y-m-d', $to.'-01')->endOfMonth();

        $crmBoards = $this->accessibleCrmBoards();
        if ($boardId !== null) {
            // Silently drop a board the user can't see rather than leaking its
            // existence — an inaccessible id just yields an empty export.
            $crmBoards = $crmBoards->where('id', $boardId)->values();
        }

        $empty = [
            'columns' => self::COLUMNS,
            'rows' => [],
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'status' => $status,
            'currency' => null,
            'multi_currency' => false,
            'total_value' => 0.0,
            'total_paid' => 0.0,
            'count' => 0,
            'generated_for' => $this->boardScopeLabel($crmBoards, $boardId),
        ];

        if ($crmBoards->isEmpty()) {
            return $empty;
        }

        // currency per board so mixed-currency exports can flag themselves.
        $currencyByBoard = $crmBoards->pluck('currency', 'id');
        $nameByBoard = Board::whereIn('id', $crmBoards->pluck('id'))->pluck('name', 'id');
        $prefixByBoard = Board::whereIn('id', $crmBoards->pluck('id'))->pluck('ticket_prefix', 'id');

        $query = Card::query()
            ->whereIn('board_id', $crmBoards->pluck('id'))
            ->whereNull('archived_at')
            ->with([
                'section:id,name',
                'contact:id,name',
                'assignedUser:id,name',
                'tags:id,name',
            ]);

        $this->applyStatusWindow($query, $status, $start, $end);

        $cards = $query->orderByDesc('done_at')
            ->orderByDesc('lost_at')
            ->orderByDesc('created_at')
            ->get();

        $rows = [];
        $totalValue = 0.0;
        $totalPaid = 0.0;
        $currencies = [];

        foreach ($cards as $card) {
            $currency = $currencyByBoard[$card->board_id] ?? 'USD';
            $currencies[$currency] = true;

            $value = (float) $card->value;
            $paid = (float) $card->amount_paid;
            $totalValue += $value;
            $totalPaid += $paid;

            $rows[] = [
                'ticket' => Card::ticketKey($prefixByBoard[$card->board_id] ?? null, $card->ticket_number),
                'deal' => (string) $card->name,
                'client' => $card->contact?->name ?? '',
                'board' => $nameByBoard[$card->board_id] ?? '',
                'stage' => $card->section?->name ?? '',
                'status' => $this->rowStatus($card),
                'value' => round($value, 2),
                'paid' => round($paid, 2),
                'currency' => $currency,
                'owner' => $card->assignedUser?->name ?? '',
                'priority' => ucfirst((string) ($card->priority ?? '')),
                'tags' => $card->tags->pluck('name')->implode(', '),
                'created' => optional($card->created_at)->format('Y-m-d') ?? '',
                'due' => $card->due_date ? Carbon::parse($card->due_date)->format('Y-m-d') : '',
                'closed' => $this->closedDate($card),
                'loss_reason' => (string) ($card->loss_reason ?? ''),
            ];
        }

        return [
            'columns' => self::COLUMNS,
            'rows' => $rows,
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'status' => $status,
            // A single-currency export reports that currency; a mixed one reports
            // null + the multi_currency flag so the UI can drop the money totals.
            'currency' => count($currencies) === 1 ? array_key_first($currencies) : null,
            'multi_currency' => count($currencies) > 1,
            'total_value' => round($totalValue, 2),
            'total_paid' => round($totalPaid, 2),
            'count' => count($rows),
            'generated_for' => $this->boardScopeLabel($crmBoards, $boardId),
        ];
    }

    /**
     * Narrow the query to the requested status and apply the period window to
     * the date column that status is keyed on. `all` windows on the deal's most
     * meaningful date (closed if closed, else created).
     */
    private function applyStatusWindow($query, string $status, Carbon $start, Carbon $end): void
    {
        switch ($status) {
            case 'won':
                $query->whereNotNull('done_at')
                    ->whereBetween('done_at', [$start, $end]);
                break;
            case 'lost':
                $query->whereNotNull('lost_at')
                    ->whereBetween('lost_at', [$start, $end]);
                break;
            case 'open':
                $query->whereNull('done_at')
                    ->whereNull('lost_at')
                    ->whereBetween('created_at', [$start, $end]);
                break;
            default: // all — window on COALESCE(done_at, lost_at, created_at)
                $query->where(function ($q) use ($start, $end) {
                    $q->whereBetween('done_at', [$start, $end])
                        ->orWhere(function ($q2) use ($start, $end) {
                            $q2->whereNull('done_at')->whereBetween('lost_at', [$start, $end]);
                        })
                        ->orWhere(function ($q3) use ($start, $end) {
                            $q3->whereNull('done_at')->whereNull('lost_at')
                                ->whereBetween('created_at', [$start, $end]);
                        });
                });
        }
    }

    private function rowStatus(Card $card): string
    {
        if ($card->done_at !== null) {
            return 'Won';
        }
        if ($card->lost_at !== null) {
            return 'Lost';
        }

        return 'Open';
    }

    private function closedDate(Card $card): string
    {
        $closed = $card->done_at ?? $card->lost_at;

        return $closed ? Carbon::parse($closed)->format('Y-m-d') : '';
    }

    /** Human label for who/what the export covers, printed on the report header. */
    private function boardScopeLabel($crmBoards, ?int $boardId): string
    {
        if ($crmBoards->isEmpty()) {
            return 'No CRM boards';
        }
        if ($boardId !== null) {
            $name = Board::whereKey($boardId)->value('name');

            return $name ? (string) $name : 'CRM board';
        }
        $n = $crmBoards->count();

        return $n === 1 ? '1 CRM pipeline' : "{$n} CRM pipelines";
    }
}
