<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Repository\BoardRepository;

class BoardService
{
    public BoardRepository $boardRepository;

    public function __construct()
    {
        $this->boardRepository = resolve(BoardRepository::class);
    }

    public function fetchAll()
    {
        return $this->boardRepository->index();
    }
}

