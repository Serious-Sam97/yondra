<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Vortex\MemoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** F-02 · after a chat turn, file what he learned about the user in the dossier. */
class ExtractVortexMemoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $userText,
        public readonly string $reply,
    ) {}

    public function handle(MemoryService $memories): void
    {
        $memories->extract($this->userId, $this->userText, $this->reply);
    }
}
