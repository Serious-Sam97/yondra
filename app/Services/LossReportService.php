<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Repository\LossReportRepository;

class LossReportService
{
    public LossReportRepository $repo;

    public function __construct()
    {
        $this->repo = resolve(LossReportRepository::class);
    }

    public function loss(string $from, string $to): array
    {
        return $this->repo->loss($from, $to);
    }
}
