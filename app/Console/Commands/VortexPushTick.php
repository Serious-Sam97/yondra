<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Vortex\PushService;
use Illuminate\Console\Command;

/** Q-03 · every minute: due reminders and 03:13 pages go out as real push. */
class VortexPushTick extends Command
{
    protected $signature = 'vortex:push-tick';

    protected $description = 'Send due Vortex reminders and 03:13 pages as Web Push';

    public function handle(PushService $push): int
    {
        $s = $push->tick();
        if ($s['reminder'] + $s['night'] > 0) {
            $this->info("pushed: {$s['reminder']} reminders, {$s['night']} night pages");
        }

        return self::SUCCESS;
    }
}
