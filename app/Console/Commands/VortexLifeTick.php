<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Models\VortexSoul;
use App\Infrastructure\Models\VortexWorldState;
use App\Services\Vortex\GreatRewindService;
use App\Services\Vortex\LabService;
use App\Services\Vortex\SoulService;
use Illuminate\Console\Command;

/**
 * C-08 · the life tick. Every hour, for everyone seen in the last week, Vortex
 * keeps living: needs decay, he sleeps, his agenda moves, the Rewinder creeps
 * closer, and he does things you'll hear about when you come back. Pure logic —
 * no LLM calls here.
 */
class VortexLifeTick extends Command
{
    protected $signature = 'vortex:life-tick';

    protected $description = 'Advance every active Vortex soul by the time that passed';

    public function handle(SoulService $souls, GreatRewindService $rewind): int
    {
        // L-09 · if the Great Rewind's hour is over, settle it (once)
        $rewind->resolve();

        $n = 0;
        VortexSoul::where('last_seen_at', '>=', now()->subDays(7))
            ->chunkById(200, function ($batch) use ($souls, &$n) {
                foreach ($batch as $soul) {
                    $souls->tick($soul);
                    app(LabService::class)->tick($soul->fresh()); // D-02 · the bench keeps working
                    $n++;
                }
            });
        // H-30 · the whole tape runs out a little every hour, for everyone
        $tape = VortexWorldState::firstOrNew(['key' => 'tape']);
        $tape->value = ['hours' => (int) ($tape->value['hours'] ?? 0) + 1];
        $tape->save();
        $this->info("ticked {$n} souls");

        return self::SUCCESS;
    }
}
