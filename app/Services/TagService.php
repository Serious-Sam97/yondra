<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Repository\TagRepository;
use App\Infrastructure\Models\Tag;

class TagService
{
    /** Palette for auto-created tags; a name always maps to the same colour. */
    private const AUTO_COLORS = ['#9aa67e', '#ffb000', '#6fe0ff', '#ff5a4d', '#ff2d95', '#8b7fd6'];

    public TagRepository $tagRepository;

    public function __construct()
    {
        $this->tagRepository = resolve(TagRepository::class);
    }

    /**
     * Return the board's tag with this name (case-insensitive), creating it with a
     * deterministic colour if absent. Used by intake field-mapping (YON-50) to turn
     * a submitted value into real board tags without pre-registering each one.
     */
    public function findOrCreateByName(int $boardId, string $name): Tag
    {
        $name = mb_substr(trim($name), 0, 50);
        $existing = Tag::where('board_id', $boardId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($existing) {
            return $existing;
        }

        $color = self::AUTO_COLORS[abs(crc32($name)) % count(self::AUTO_COLORS)];

        return Tag::create(['board_id' => $boardId, 'name' => $name, 'color' => $color]);
    }

    public function forBoard(int $boardId): mixed
    {
        return $this->tagRepository->forBoard($boardId);
    }

    public function create(array $data): mixed
    {
        return $this->tagRepository->save($data);
    }

    public function edit(int $boardId, int $id, array $data): mixed
    {
        return $this->tagRepository->update($boardId, $id, $data);
    }

    public function remove(int $boardId, int $id): void
    {
        $this->tagRepository->delete($boardId, $id);
    }
}
