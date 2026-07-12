<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface BoardRepository
{
    public function index();

    public function show($id);

    public function save($request);

    public function update($request);

    public function delete($request);

    public function setArchived(int $id, bool $archived);

    public function duplicate(int $id, ?string $name, bool $includeCards);
}
