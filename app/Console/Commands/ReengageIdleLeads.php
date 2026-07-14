<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\WhatsappReengagementPolicy;
use App\Notifications\LeadDroppedNotification;
use App\Services\CardService;
use App\Services\Notifier;
use App\Services\WhatsappService;
use Illuminate\Console\Command;

/**
 * Daily re-engagement ladder (card YON-62). For each board with an enabled policy,
 * nudge idle leads with an approved WhatsApp template — spaced by retry_interval_days,
 * capped at max_attempts — then retire the unresponsive ones (move to the Lost stage,
 * or archive, and notify the owner). Mirrors SendDueDateReminders.
 */
class ReengageIdleLeads extends Command
{
    protected $signature = 'whatsapp:reengage {--dry-run : Log intended actions without sending or moving anything}';

    protected $description = 'Re-engage idle WhatsApp leads and drop out the unresponsive ones.';

    public function handle(WhatsappService $whatsapp, Notifier $notifier, CardService $cardService): int
    {
        $dry = (bool) $this->option('dry-run');
        $sent = 0;
        $dropped = 0;

        $policies = WhatsappReengagementPolicy::where('enabled', true)
            ->with('board')
            ->get()
            // Only boards actually wired to WhatsApp can send.
            ->filter(fn (WhatsappReengagementPolicy $p) => $p->board && $p->board->whatsapp_phone_number_id && $p->template_name);

        foreach ($policies as $policy) {
            $board = $policy->board;

            // Open leads (not archived, not completed, not already dropped) whose
            // customer has gone silent for at least idle_days.
            $cards = Card::where('board_id', $board->id)
                ->whereNull('archived_at')
                ->whereNull('done_at')
                ->when($policy->lost_section_id, fn ($q) => $q->where('section_id', '!=', $policy->lost_section_id))
                ->whereHas('whatsappConversations', function ($q) use ($policy) {
                    $q->whereNotNull('last_inbound_at')
                        ->where('last_inbound_at', '<=', now()->subDays($policy->idle_days));
                })
                ->get();

            foreach ($cards as $card) {
                $conversation = $card->whatsappConversations()->first();
                // Never cold-start, and respect the quality brake (mirrors runStageAutomation).
                if (! $conversation || in_array($conversation->quality_state, ['yellow', 'red'], true)) {
                    continue;
                }

                // Exhausted the ladder with no reply → drop out.
                if ($conversation->reengagement_attempts >= $policy->max_attempts) {
                    if ($dry) {
                        $this->line("[dry] drop card {$card->id} (\"{$card->name}\")");
                    } else {
                        if ($policy->lost_section_id) {
                            // Reuse the normal move path so section_entered_at/events stay consistent.
                            $cardService->edit([
                                'id' => (int) $card->id,
                                'board_id' => (int) $board->id,
                                'section_id' => (int) $policy->lost_section_id,
                            ]);
                        } else {
                            $card->update(['archived_at' => now()]);
                        }
                        $recipientId = $card->assigned_user_id ?: $board->user_id;
                        if ($recipient = User::find($recipientId)) {
                            $notifier->send($recipient, new LeadDroppedNotification(
                                boardId: (int) $board->id,
                                cardId: (int) $card->id,
                                cardName: (string) $card->name,
                            ));
                        }
                    }
                    $dropped++;

                    continue;
                }

                // Still has attempts left — send one, but only if we've waited the interval.
                $last = $conversation->last_reengagement_at;
                if ($last !== null && $last->gt(now()->subDays($policy->retry_interval_days))) {
                    continue;
                }

                if ($dry) {
                    $this->line("[dry] send to card {$card->id} (\"{$card->name}\"), attempt ".($conversation->reengagement_attempts + 1));
                } else {
                    $whatsapp->sendTemplateToCard($card, $policy->template_name, $policy->language, [], null);
                    $conversation->increment('reengagement_attempts');
                    $conversation->update(['last_reengagement_at' => now()]);
                }
                $sent++;
            }
        }

        $this->info("Re-engaged {$sent}, dropped {$dropped}.");

        return self::SUCCESS;
    }
}
