<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use Illuminate\Support\Facades\Cache;

/**
 * T-06 · what his brain costs. Every LLM feature has a per-user daily cap and
 * all of them share one global daily budget (config/vortex_mk5.php). Past
 * either, spend() throws OutOfBudget — every caller already has a local
 * template fallback inside its try/catch, so he just gets dumber for the day.
 * A crisis turn is never metered.
 */
final class AiBudget
{
    public const LINE = 'my brain is out of budget today. enjoy the dumb version.';

    /** @throws OutOfBudget */
    public static function spend(int $userId, string $feature): void
    {
        if (! self::allows($userId, $feature)) {
            throw new OutOfBudget($feature);
        }
        $cost = (int) config("vortex_mk5.ai.features.{$feature}.cost", 1);
        $day = now()->toDateString();
        foreach (["vxai:{$day}:u{$userId}:{$feature}", "vxai:{$day}:global"] as $k) {
            Cache::add($k, 0, now()->addDays(2));
            Cache::increment($k, $cost);
        }
    }

    public static function allows(int $userId, string $feature): bool
    {
        $day = now()->toDateString();
        $cap = (int) config("vortex_mk5.ai.features.{$feature}.cap", 50);
        $global = (int) config('vortex_mk5.ai.global_daily', 20000);

        return (int) Cache::get("vxai:{$day}:u{$userId}:{$feature}", 0) < $cap
            && (int) Cache::get("vxai:{$day}:global", 0) < $global;
    }

    /** today's usage (for the debug panel) */
    public static function today(int $userId): array
    {
        $day = now()->toDateString();
        $out = ['global' => (int) Cache::get("vxai:{$day}:global", 0), 'global_cap' => (int) config('vortex_mk5.ai.global_daily')];
        foreach (array_keys(config('vortex_mk5.ai.features', [])) as $f) {
            $out['features'][$f] = ['used' => (int) Cache::get("vxai:{$day}:u{$userId}:{$f}", 0), 'cap' => (int) config("vortex_mk5.ai.features.{$f}.cap")];
        }

        return $out;
    }
}
