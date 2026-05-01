<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface TagRepository
{
    public function forBoard(int $boardId): mixed;
    public function save(array $data): mixed;
    public function delete(int $id): void;
}
