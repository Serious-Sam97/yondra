<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\TagRepository;
use App\Infrastructure\Models\Tag;

class TagModelRepository implements TagRepository
{
    public function forBoard(int $boardId): mixed
    {
        return Tag::where('board_id', $boardId)->get();
    }

    public function save(array $data): mixed
    {
        return Tag::create([
            'board_id' => $data['board_id'],
            'name'     => $data['name'],
            'color'    => $data['color'],
        ]);
    }

    public function delete(int $id): void
    {
        Tag::findOrFail($id)->delete();
    }
}
