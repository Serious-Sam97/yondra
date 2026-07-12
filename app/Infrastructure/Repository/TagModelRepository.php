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
            'name' => $data['name'],
            'color' => $data['color'],
        ]);
    }

    public function update(int $boardId, int $id, array $data): mixed
    {
        $tag = Tag::where('board_id', $boardId)->findOrFail($id);
        $tag->update(array_intersect_key($data, array_flip(['name', 'color'])));

        return $tag->fresh();
    }

    public function delete(int $boardId, int $id): void
    {
        Tag::where('board_id', $boardId)->findOrFail($id)->delete();
    }
}
