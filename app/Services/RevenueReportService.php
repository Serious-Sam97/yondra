<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Repository\RevenueReportRepository;

class RevenueReportService
{
    public RevenueReportRepository $repo;

    public function __construct()
    {
        $this->repo = resolve(RevenueReportRepository::class);
    }

    public function revenue(string $from, string $to): array
    {
        return $this->repo->revenue($from, $to);
    }
}
