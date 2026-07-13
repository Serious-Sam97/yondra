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
 * Runs a board-level AI standup / sprint summary off the request thread, streaming
 * `ai.token`/`ai.done` frames (scope:'board') on the board channel. Board access was
 * checked by the controller before dispatch; only scalar IDs are carried.
 */
class GenerateBoardSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $boardId,
        public readonly string $requestId,
        public readonly ?int $sprintId = null,
    ) {}

    public function handle(AiAssistService $ai): void
    {
        $ai->streamBoardSummary($this->boardId, $this->requestId, $this->sprintId);
    }
}
