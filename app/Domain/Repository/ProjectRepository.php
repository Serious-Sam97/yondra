<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface ProjectRepository
{
    public function index();
    public function show(int $id);
    public function save(array $request);
    public function update(array $request);
    public function delete(int $id);
    public function addMember(int $projectId, int $userId, string $role);
    public function updateMember(int $projectId, int $userId, string $role);
    public function removeMember(int $projectId, int $userId);
}
