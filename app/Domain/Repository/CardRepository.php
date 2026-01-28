<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface CardRepository {
    public function index();
    public function save($request);
    public function update($request);
    public function delete($request);
}