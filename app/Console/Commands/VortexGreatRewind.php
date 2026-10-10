<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Vortex\GreatRewindService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * L-09 · schedule the Great Rewind: one hour, every user, a community goal.
 * Clues start two weeks before. Example:
 *   php artisan vortex:great-rewind "2026-10-31 21:00" --minutes=60 --goal=500
 */
class VortexGreatRewind extends Command
{
    protected $signature = 'vortex:great-rewind {start? : when it begins (app timezone)} {--minutes=60} {--goal=500} {--status}';

    protected $description = 'Schedule (or inspect) the global Great Rewind event';

    public function handle(GreatRewindService $rewind): int
    {
        if ($this->option('status') || ! $this->argument('start')) {
            $this->line(json_encode($rewind->view(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $start = CarbonImmutable::parse((string) $this->argument('start'));
        $v = $rewind->schedule($start, max(5, (int) $this->option('minutes')), max(1, (int) $this->option('goal')));
        $this->info('the Great Rewind is on the tape: '.$start->toDayDateTimeString());
        $this->line(json_encode($v, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
