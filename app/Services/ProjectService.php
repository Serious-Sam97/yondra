<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Repository\ProjectRepository;

class ProjectService
{
    public ProjectRepository $repo;

    public function __construct()
    {
        $this->repo = resolve(ProjectRepository::class);
    }

    public function fetchAll()                                              { return $this->repo->index(); }
    public function fetchOne(int $id)                                      { return $this->repo->show($id); }
    public function create(array $data)                                    { return $this->repo->save($data); }
    public function update(array $data)                                    { return $this->repo->update($data); }
    public function remove(int $id)                                        { return $this->repo->delete($id); }
    public function addMember(int $projectId, int $userId, string $role)   { return $this->repo->addMember($projectId, $userId, $role); }
    public function updateMember(int $projectId, int $userId, string $role){ return $this->repo->updateMember($projectId, $userId, $role); }
    public function removeMember(int $projectId, int $userId)              { return $this->repo->removeMember($projectId, $userId); }
}
