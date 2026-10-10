<?php

namespace App\Services\Yutopia;

use App\Events\ProjectEvent;
use App\Infrastructure\Models\YutopiaSpace;
use Illuminate\Support\Facades\Cache;

// Who is in Yutopia right now, per space. The world-server pushes a full snapshot
// on every change (and a heartbeat), so the cache entry simply expires if it dies.
class YutopiaPresence
{
    private const TTL = 150;

    public function put(YutopiaSpace $space, array $entries): array
    {
        $snapshot = [
            'spaceId' => $space->id,
            'projectId' => $space->project_id,
            'people' => array_values(array_map(fn ($e) => [
                'userId' => (int) $e['user_id'],
                'name' => (string) ($e['name'] ?? ''),
                'area' => $e['area'] ?? null,
                'areaName' => $e['area_name'] ?? null,
                'status' => in_array($e['status'] ?? 'online', ['online', 'away', 'dnd'], true) ? $e['status'] : 'online',
            ], $entries)),
            'at' => now()->toIso8601String(),
        ];
        $key = self::key($space->id);
        $changed = Cache::get($key)['people'] ?? null;
        Cache::put($key, $snapshot, self::TTL);

        if ($space->project_id && $changed !== $snapshot['people']) {
            broadcast(new ProjectEvent($space->project_id, 'yutopia.presence', $snapshot));
        }

        return $snapshot;
    }

    public function get(YutopiaSpace $space): array
    {
        return Cache::get(self::key($space->id)) ?? ['spaceId' => $space->id, 'projectId' => $space->project_id, 'people' => [], 'at' => null];
    }

    private static function key(int $spaceId): string
    {
        return 'yutopia:presence:'.$spaceId;
    }
}
