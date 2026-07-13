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
 * Runs one AI-assist action (summarize, describe, checklist, tests, reply, rewrite) off
 * the request thread, so the outbound streaming LLM call never blocks the HTTP response.
 * Board access was checked by the controller before dispatch; only scalar IDs + the
 * action and its options are carried (never an Eloquent model), and the service re-loads
 * the card.
 *
 * @phpstan-param array<string,mixed> $options
 */
class GenerateAiAssistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string,mixed>  $options
     */
    public function __construct(
        public readonly int $boardId,
        public readonly int $cardId,
        public readonly string $requestId,
        public readonly string $action,
        public readonly array $options = [],
    ) {}

    public function handle(AiAssistService $ai): void
    {
        $ai->run($this->boardId, $this->cardId, $this->requestId, $this->action, $this->options);
    }
}
