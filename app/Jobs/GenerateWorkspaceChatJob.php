<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AiAssistService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs a turn of Vortex (the user-scoped workspace assistant) off the request thread,
 * streaming `ai.token`/`ai.done` frames (scope:'vortex-chat') on the user's private
 * channel. The controller authenticated the user before dispatch; only scalar IDs and
 * the conversation (plain role/content pairs) are carried.
 */
class GenerateWorkspaceChatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<array{role:string,content:string}>  $messages
     * @param  list<array{type:string,id:int}>  $mounts  Contexts the user mounted
     *                                                   (authorization-checked by the controller).
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $requestId,
        public readonly array $messages,
        public readonly array $mounts = [],
    ) {}

    public function handle(AiAssistService $ai): void
    {
        $ai->streamWorkspaceChat($this->userId, $this->requestId, $this->messages, $this->mounts);
    }
}
