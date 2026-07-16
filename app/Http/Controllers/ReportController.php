<?php

namespace App\Http\Controllers;

use App\Infrastructure\Repository\DealsExportRepository;
use App\Services\ConversionReportService;
use App\Services\DealsExportService;
use App\Services\LossReportService;
use App\Services\RevenueReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private RevenueReportService $revenue;

    private ConversionReportService $conversion;

    private LossReportService $loss;

    private DealsExportService $deals;

    public function __construct()
    {
        $this->revenue = resolve(RevenueReportService::class);
        $this->conversion = resolve(ConversionReportService::class);
        $this->loss = resolve(LossReportService::class);
        $this->deals = resolve(DealsExportService::class);
    }

    /**
     * Monthly revenue report (YON-64). Optional ?from=YYYY-MM&to=YYYY-MM;
     * defaults to the last 12 months ending this month. The window is clamped
     * to 24 months so the underlying query stays bounded.
     */
    public function revenue(Request $request)
    {
        [$from, $to] = $this->monthWindow($request);

        return $this->revenue->revenue($from, $to);
    }

    /**
     * Monthly conversion-rate report (YON-65): cards won each month divided by
     * the total cards on the user's CRM boards. Same period/window contract as
     * the revenue report.
     */
    public function conversion(Request $request)
    {
        [$from, $to] = $this->monthWindow($request);

        return $this->conversion->conversion($from, $to);
    }

    /**
     * Monthly loss report (YON-66): deals lost each month, broken down by the
     * configured loss reason (+ lost pipeline value). Same period/window
     * contract as the revenue report.
     */
    public function loss(Request $request)
    {
        [$from, $to] = $this->monthWindow($request);

        return $this->loss->loss($from, $to);
    }

    /**
     * Deals export (YON-67): a flat, exportable ledger of CRM deals over the
     * selected period/status/board. Answers JSON by default (rows + column
     * spec + totals, for the on-screen preview and the printable report) or a
     * streamed CSV download when ?format=csv. Same period/window contract as
     * the other reports; adds ?status=all|won|lost|open and ?board_id=N.
     */
    public function deals(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', DealsExportRepository::STATUSES)],
            'board_id' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        [$from, $to] = $this->monthWindow($request);
        $status = $validated['status'] ?? 'all';
        $boardId = isset($validated['board_id']) ? (int) $validated['board_id'] : null;

        $payload = $this->deals->export($from, $to, $status, $boardId);

        if (($validated['format'] ?? 'json') !== 'csv') {
            return $payload;
        }

        $csv = $this->deals->toCsv($payload);
        $filename = sprintf('deals-%s_%s-to-%s.csv', $status, $payload['from'], $payload['to']);

        return new StreamedResponse(function () use ($csv) {
            echo $csv;
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Resolve the ?from/?to month params into an inclusive [from, to] pair of
     * "YYYY-MM" strings: defaults to the last 12 months, tolerates a reversed
     * range, and clamps the window to at most 24 months back from `to`.
     *
     * @return array{0:string,1:string}
     */
    private function monthWindow(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
        ]);

        $to = isset($validated['to'])
            ? Carbon::createFromFormat('Y-m-d', $validated['to'].'-01')->startOfMonth()
            : Carbon::now()->startOfMonth();
        $from = isset($validated['from'])
            ? Carbon::createFromFormat('Y-m-d', $validated['from'].'-01')->startOfMonth()
            : $to->copy()->subMonths(11);

        // Tolerate a reversed range by swapping rather than erroring.
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        // Bound the window: at most 24 months, counting back from `to`.
        $earliest = $to->copy()->subMonths(23);
        if ($from->lt($earliest)) {
            $from = $earliest;
        }

        return [$from->format('Y-m'), $to->format('Y-m')];
    }
}
