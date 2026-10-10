<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Who counts as "your team" for the mascot's social bits: anyone you share a board with. */
final class Teammates
{
    /** @return list<int> */
    public static function of(int $userId): array
    {
        return Cache::remember('vortex:mates:'.$userId, 600, function () use ($userId) {
            $boards = DB::table('boards')->where('user_id', $userId)->pluck('id')
                ->merge(DB::table('board_shares')->where('user_id', $userId)->pluck('board_id'))
                ->unique();
            if ($boards->isEmpty()) {
                return [];
            }

            return DB::table('boards')->whereIn('id', $boards)->pluck('user_id')
                ->merge(DB::table('board_shares')->whereIn('board_id', $boards)->pluck('user_id'))
                ->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === $userId)->values()->all();
        });
    }
}
