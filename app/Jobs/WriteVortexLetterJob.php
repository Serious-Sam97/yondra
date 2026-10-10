<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Infrastructure\Models\User;
use App\Services\Vortex\JournalService;
use App\Services\Vortex\PushService;
use App\Services\Vortex\SoulService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** F-14 · write this month's letter off the request thread. */
class WriteVortexLetterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $userId) {}

    public function handle(JournalService $journal, SoulService $souls, PushService $push): void
    {
        $user = User::find($this->userId);
        if ($user && ($soul = $souls->for($user)) && $journal->writeLetter($user, $soul)) {
            // Q-03 · a new letter is one of the few things worth a push
            $push->send($user, 'letter', "you've got mail. a real letter. from me. it's in your profile.", '/profile#vortex-letters', $soul);
        }
    }
}
