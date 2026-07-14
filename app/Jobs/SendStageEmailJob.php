<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\EmailAutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a card enters a section. Runs the stage's email automation (if any),
 * off the web request so a drag never blocks on outbound mail. Sibling of
 * {@see SendStageWhatsappJob}.
 */
class SendStageEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $cardId,
        public readonly int $sectionId,
    ) {}

    public function handle(EmailAutomationService $emails): void
    {
        $emails->runStageAutomation($this->cardId, $this->sectionId);
    }
}
