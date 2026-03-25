<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Repository\SectionRepository;

class SectionService
{
    public SectionRepository $sectionRepository;

    public function __construct()
    {
        $this->sectionRepository = resolve(SectionRepository::class);
    }

    public function create(array $data): mixed
    {
        return $this->sectionRepository->save($data);
    }

    public function remove(int $id): void
    {
        $this->sectionRepository->delete(['id' => $id]);
    }
}
