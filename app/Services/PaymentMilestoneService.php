<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\PaymentMilestone;
use App\Infrastructure\Models\PaymentMilestoneEvent;
use App\Infrastructure\Models\Section;
use Illuminate\Database\QueryException;

/**
 * The payment-milestone engine (YON-63). After a payment lands, evaluates the
 * board's milestone rules against the deal's paid %, and for each newly-crossed
 * milestone fires its actions: send a message (WhatsApp/email) and/or move the
 * card to a target stage (e.g. "invoice" at 100%). Fires each milestone once,
 * guaranteed by the unique (card_id, milestone_id) event row.
 */
class PaymentMilestoneService
{
    public function __construct(
        private readonly WhatsappService $whatsapp,
        private readonly EmailAutomationService $email,
        private readonly PaymentService $payments,
        private readonly QualityGate $qualityGate,
        private readonly CardService $cards,
    ) {}

    public function evaluate(int $cardId): void
    {
        $card = Card::with('board')->find($cardId);
        $board = $card?->board;
        if (! $card || ! $board) {
            return;
        }

        // No deal value → no denominator → no % → nothing to evaluate.
        $value = $card->value !== null ? (float) $card->value : null;
        if ($value === null || $value <= 0) {
            return;
        }

        $paid = (float) $card->amount_paid;
        $pct = $paid / $value * 100;

        // Highest whole-percent threshold the deal has reached. Flooring an int bound
        // (rather than comparing the smallint column to a float) keeps Postgres happy,
        // and the +0.0001 epsilon absorbs float rounding so 100% reliably includes 100.
        $reached = (int) floor($pct + 0.0001);

        // Enabled milestones already crossed, cheapest threshold first, that don't yet
        // have an event row for this card.
        $milestones = PaymentMilestone::where('board_id', $board->id)
            ->where('enabled', true)
            ->where('threshold_pct', '<=', $reached)
            ->orderBy('threshold_pct')
            ->get();

        foreach ($milestones as $milestone) {
            $event = $this->claim($card, $board, $milestone, $paid);
            if ($event === null) {
                continue; // already fired (or claimed concurrently)
            }
            $this->fire($card, $board, $milestone, $event);
        }
    }

    /**
     * Atomically claim a milestone for this card by inserting its event row. Returns
     * the fresh event, or null if it already existed (unique constraint) — which is
     * exactly what makes a milestone fire at most once per card.
     */
    private function claim(Card $card, Board $board, PaymentMilestone $milestone, float $paid): ?PaymentMilestoneEvent
    {
        if (PaymentMilestoneEvent::where('card_id', $card->id)->where('milestone_id', $milestone->id)->exists()) {
            return null;
        }

        try {
            return PaymentMilestoneEvent::create([
                'card_id' => (int) $card->id,
                'board_id' => (int) $board->id,
                'milestone_id' => (int) $milestone->id,
                'threshold_pct' => $milestone->threshold_pct,
                'amount_paid_at_trigger' => $paid,
                'message_status' => 'none',
                'triggered_at' => now(),
            ]);
        } catch (QueryException) {
            return null; // lost the race to a concurrent worker
        }
    }

    /** Run a milestone's actions and record the outcome on its event row. */
    private function fire(Card $card, Board $board, PaymentMilestone $milestone, PaymentMilestoneEvent $event): void
    {
        if ($milestone->notify) {
            $result = $this->sendMessage($card, $board, $milestone);
            $event->message_status = $result['status'];
            $event->message_channel = $result['channel'] ?? null;
            $event->error = $result['error'] ?? null;
        }

        if ($milestone->move_to_section_id) {
            $event->moved_to_section_id = $this->moveCard($card, $board, (int) $milestone->move_to_section_id, $event);
        }

        $event->save();

        BoardActivity::create([
            'board_id' => (int) $board->id,
            'user_id' => null,
            'type' => 'payment.milestone',
            'description' => 'reached '.$milestone->threshold_pct.'% payment on "'.$card->name.'"',
        ]);
        broadcast(new BoardEvent($board->id, 'card.payment.milestone', [
            'card_id' => (int) $card->id,
            'threshold_pct' => $milestone->threshold_pct,
        ]));
    }

    /**
     * Resolve the channel and send. `auto` prefers WhatsApp when a conversation +
     * template exist, and falls back to email when WhatsApp is unavailable.
     *
     * @return array{status:string,channel?:string,error:?string}
     */
    private function sendMessage(Card $card, Board $board, PaymentMilestone $milestone): array
    {
        $channel = $milestone->channel ?: 'auto';
        $vars = $this->paymentVars($card, $milestone);

        if (($channel === 'whatsapp' || $channel === 'auto') && $milestone->whatsapp_template_name) {
            $result = $this->whatsapp->tryTemplateToCard(
                $card,
                $milestone->whatsapp_template_name,
                $milestone->language ?: 'en',
            );
            // Pinned WhatsApp always reports its result; `auto` falls through to email
            // only when WhatsApp was skipped (no conversation / degraded quality).
            if ($channel === 'whatsapp' || $result['status'] !== 'skipped') {
                return $result + ['channel' => 'whatsapp'];
            }
        } elseif ($channel === 'whatsapp') {
            return ['status' => 'skipped', 'channel' => 'whatsapp', 'error' => 'No WhatsApp template configured.'];
        }

        $result = $this->email->sendToCard(
            $card,
            $milestone->email_subject ?: 'Payment update',
            $milestone->email_body ?: '',
            $vars,
            $milestone->label,
        );

        return $result + ['channel' => 'email'];
    }

    /** Move the card to the target stage, honouring the QA gate on the done column. */
    private function moveCard(Card $card, Board $board, int $sectionId, PaymentMilestoneEvent $event): ?int
    {
        if ((int) $card->section_id === $sectionId) {
            return $sectionId; // already there
        }

        $section = Section::find($sectionId);
        if (! $section || (int) $section->board_id !== (int) $board->id) {
            return null;
        }

        $blocking = $this->qualityGate->blocking($board, [(int) $card->id], $section);
        if (! empty($blocking)) {
            $event->error = trim(($event->error ? $event->error.' ' : '').'Stage move blocked by quality gate.');

            return null;
        }

        $this->cards->edit([
            'id' => (int) $card->id,
            'board_id' => (int) $board->id,
            'section_id' => $sectionId,
        ]);

        return $sectionId;
    }

    /** Payment-specific template variables layered on top of the stage-email vars. */
    private function paymentVars(Card $card, PaymentMilestone $milestone): array
    {
        $currency = $card->board?->currency ?? 'BRL';
        $value = (float) ($card->value ?? 0);
        $paid = (float) $card->amount_paid;
        $remaining = max(0.0, $value - $paid);
        $pct = $value > 0 ? (int) round($paid / $value * 100) : 0;

        return [
            'amount_paid' => $this->payments->formatMoney($paid, $currency),
            'amount_remaining' => $this->payments->formatMoney($remaining, $currency),
            'payment_pct' => $pct.'%',
            'milestone_label' => (string) ($milestone->label ?? ''),
        ];
    }
}
