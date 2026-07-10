<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;

class DashboardController extends Controller
{
    private DashboardService $service;

    public function __construct()
    {
        $this->service = resolve(DashboardService::class);
    }

    /** Aggregate payload for the /dashboard command-center home base. */
    public function index()
    {
        return $this->service->index();
    }
}
