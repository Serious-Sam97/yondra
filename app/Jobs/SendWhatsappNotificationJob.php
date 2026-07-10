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
 * Delivers one WhatsApp-channel notification off the request thread, so notifying a
 * user never blocks on an outbound Cloud API call.
 */
class SendWhatsappNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int,array<string,mixed>>  $components
     */
    public function __construct(
        public readonly ?int $boardId,
        public readonly string $to,
        public readonly string $template,
        public readonly string $language,
        public readonly array $components,
    ) {}

    public function handle(WhatsappService $whatsapp): void
    {
        $whatsapp->sendNotificationTemplate(
            $this->boardId,
            $this->to,
            $this->template,
            $this->language,
            $this->components,
        );
    }
}
