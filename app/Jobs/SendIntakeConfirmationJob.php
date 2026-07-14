<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\IntakeConfirmationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the intake opt-in confirmation email off the web request, so a form POST
 * never blocks on outbound mail. Sibling of {@see SendStageEmailJob}.
 */
class SendIntakeConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $contactId) {}

    public function handle(IntakeConfirmationService $service): void
    {
        $service->sendFor($this->contactId);
    }
}
