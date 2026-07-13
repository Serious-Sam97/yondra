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
 * Runs a streamed card-thread summary off the request thread, so the outbound LLM
 * call never blocks the HTTP response. Board access was checked by the controller
 * before dispatch; only scalar IDs are carried (the house job convention — never an
 * Eloquent model), and the service re-loads the card.
 */
class GenerateCardSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $boardId,
        public readonly int $cardId,
        public readonly string $requestId,
    ) {}

    public function handle(AiAssistService $ai): void
    {
        $ai->summarizeCard($this->boardId, $this->cardId, $this->requestId);
    }
}
