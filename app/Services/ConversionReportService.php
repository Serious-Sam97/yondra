<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Repository\ConversionReportRepository;

class ConversionReportService
{
    public ConversionReportRepository $repo;

    public function __construct()
    {
        $this->repo = resolve(ConversionReportRepository::class);
    }

    public function conversion(string $from, string $to): array
    {
        return $this->repo->conversion($from, $to);
    }
}
