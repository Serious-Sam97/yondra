<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexSoul;
use App\Services\Vortex\OutsideService;
use Illuminate\Console\Command;

/** Q-01/Q-09 · Monday morning: the Void Report by email and to Slack, for whoever asked. */
class VortexOutsideWeekly extends Command
{
    protected $signature = 'vortex:outside-weekly';

    protected $description = 'Send the weekly Void Report (email / Slack) to users who opted in';

    public function handle(OutsideService $outside): int
    {
        $mail = 0;
        $slack = 0;
        VortexSoul::query()->chunkById(200, function ($souls) use ($outside, &$mail, &$slack) {
            foreach ($souls as $soul) {
                $o = $soul->state['outside'] ?? [];
                if (! ($o['email'] ?? false) && ! isset($o['slack'])) {
                    continue;
                }
                $user = User::find($soul->user_id);
                if (! $user) {
                    continue;
                }
                $mail += (int) $outside->sendWeekly($user, $soul);
                $slack += (int) $outside->slack($user, $soul);
            }
        });
        $this->info("void reports: {$mail} emails, {$slack} slack posts");

        return self::SUCCESS;
    }
}
