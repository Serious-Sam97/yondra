<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Repository\DashboardModelRepository;

class DashboardService
{
    public DashboardModelRepository $repo;

    public function __construct()
    {
        $this->repo = resolve(DashboardModelRepository::class);
    }

    public function index(): array
    {
        return $this->repo->index();
    }
}
