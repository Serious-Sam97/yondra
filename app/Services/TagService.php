<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Repository\TagRepository;

class TagService
{
    public TagRepository $tagRepository;

    public function __construct()
    {
        $this->tagRepository = resolve(TagRepository::class);
    }

    public function forBoard(int $boardId): mixed
    {
        return $this->tagRepository->forBoard($boardId);
    }

    public function create(array $data): mixed
    {
        return $this->tagRepository->save($data);
    }

    public function remove(int $id): void
    {
        $this->tagRepository->delete($id);
    }
}
