<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a card enters a section. Runs the stage's WhatsApp automation (if any),
 * off the web request so a drag never blocks on an outbound API call.
 */
class SendStageWhatsappJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $cardId,
        public readonly int $sectionId,
    ) {}

    public function handle(WhatsappService $whatsapp): void
    {
        $whatsapp->runStageAutomation($this->cardId, $this->sectionId);
    }
}
