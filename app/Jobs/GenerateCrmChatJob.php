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
 * Runs a turn of the board-level CRM assistant off the request thread, streaming
 * `ai.token`/`ai.done` frames (scope:'crm-chat') on the board channel. Board access was
 * checked by the controller before dispatch; only scalar IDs and the conversation
 * (plain role/content pairs) are carried.
 *
 * @param  list<array{role:string,content:string}>  $messages
 */
class GenerateCrmChatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<array{role:string,content:string}>  $messages
     */
    public function __construct(
        public readonly int $boardId,
        public readonly string $requestId,
        public readonly array $messages,
    ) {}

    public function handle(AiAssistService $ai): void
    {
        $ai->streamCrmChat($this->boardId, $this->requestId, $this->messages);
    }
}
