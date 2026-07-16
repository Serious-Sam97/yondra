<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Repository\TagRepository;
use App\Infrastructure\Models\Tag;
use Illuminate\Validation\ValidationException;

class TagService
{
    /** Palette for auto-created tags; a name always maps to the same colour. */
    private const AUTO_COLORS = ['#9aa67e', '#ffb000', '#6fe0ff', '#ff5a4d', '#ff2d95', '#8b7fd6'];

    /**
     * Canonical "channel" tags (YON-60). Seeded onto every board with locked
     * names and semantic colours so cards can be tagged by the channel a
     * request came through (e.g. blue = email). Users may recolour these but
     * cannot rename or delete them; free-form labels are 'custom' tags instead.
     */
    public const CHANNEL_TAGS = [
        ['name' => 'WhatsApp', 'color' => '#22c55e'],
        ['name' => 'Email', 'color' => '#3b82f6'],
        ['name' => 'Phone', 'color' => '#f59e0b'],
        ['name' => 'Instagram', 'color' => '#ec4899'],
    ];

    public TagRepository $tagRepository;

    public function __construct()
    {
        $this->tagRepository = resolve(TagRepository::class);
    }

    /**
     * Ensure the board has the canonical channel tags. Idempotent: an existing
     * channel tag with a given name (case-insensitive) is left untouched, so
     * this is safe to call on board creation and as a retroactive backfill.
     */
    public function seedChannelTags(int $boardId): void
    {
        foreach (self::CHANNEL_TAGS as $channel) {
            $exists = Tag::where('board_id', $boardId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($channel['name'])])
                ->exists();
            if ($exists) {
                continue;
            }
            Tag::create([
                'board_id' => $boardId,
                'name' => $channel['name'],
                'color' => $channel['color'],
                'kind' => 'channel',
            ]);
        }
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

        return Tag::create(['board_id' => $boardId, 'name' => $name, 'color' => $color, 'kind' => 'custom']);
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
        // Channel tags have locked names — only the colour may change.
        $tag = Tag::where('board_id', $boardId)->findOrFail($id);
        if ($tag->kind === 'channel') {
            unset($data['name']);
        }

        return $this->tagRepository->update($boardId, $id, $data);
    }

    public function remove(int $boardId, int $id): void
    {
        $tag = Tag::where('board_id', $boardId)->findOrFail($id);
        if ($tag->kind === 'channel') {
            throw ValidationException::withMessages([
                'tag' => 'Channel tags are built in and cannot be deleted.',
            ]);
        }

        $this->tagRepository->delete($boardId, $id);
    }
}
