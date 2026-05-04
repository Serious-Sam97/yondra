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

    public function rename(int $id, string $name): mixed
    {
        return $this->sectionRepository->update(['id' => $id, 'name' => $name]);
    }

    public function remove(int $id): void
    {
        $this->sectionRepository->delete(['id' => $id]);
    }

    public function reorder(array $sectionIds): void
    {
        $this->sectionRepository->reorder($sectionIds);
    }
}
