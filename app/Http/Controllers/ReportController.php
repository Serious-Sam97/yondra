<?php

namespace App\Http\Controllers;

use App\Services\RevenueReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    private RevenueReportService $service;

    public function __construct()
    {
        $this->service = resolve(RevenueReportService::class);
    }

    /**
     * Monthly revenue report (YON-64). Optional ?from=YYYY-MM&to=YYYY-MM;
     * defaults to the last 12 months ending this month. The window is clamped
     * to 24 months so the underlying query stays bounded.
     */
    public function revenue(Request $request)
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

        return $this->service->revenue($from->format('Y-m'), $to->format('Y-m'));
    }
}
