<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface SectionRepository {
    public function index();
    public function save($request);
    public function update($request);
    public function delete($request);
    public function reorder(array $sectionIds): void;
}