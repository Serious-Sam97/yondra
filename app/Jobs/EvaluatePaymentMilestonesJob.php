<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PaymentMilestoneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a payment lands on a card (YON-63). Evaluates the board's payment
 * milestones against the deal's new paid % — off the web request so recording a
 * payment never blocks on outbound WhatsApp/email. Sibling of {@see SendStageEmailJob}.
 */
class EvaluatePaymentMilestonesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $cardId,
    ) {}

    public function handle(PaymentMilestoneService $milestones): void
    {
        $milestones->evaluate($this->cardId);
    }
}
