<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\WhatsappConversation;
use App\Infrastructure\Models\WhatsappMessage;
use App\Infrastructure\Models\WhatsappStageAutomation;
use App\Services\Whatsapp\BspDriver;
use App\Services\Whatsapp\MetaCloudDriver;
use App\Services\Whatsapp\SendResult;
use App\Services\Whatsapp\WhatsappDriver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WhatsappService
{
    /** Pick the driver for a board (its pinned provider, else the config default). */
    public function driverFor(Board $board): WhatsappDriver
    {
        return match ($board->whatsapp_provider ?: config('services.whatsapp.driver')) {
            'bsp' => app(BspDriver::class),
            default => app(MetaCloudDriver::class),
        };
    }

    // ---------------------------------------------------------------------
    // Inbound (webhook) — persist messages + status updates, broadcast live.
    // ---------------------------------------------------------------------

    /**
     * Ingest a Cloud-API webhook body. Returns the number of inbound messages stored.
     * The payload shape is identical for Meta and BSP transports.
     */
    public function handleInbound(Board $board, array $payload): int
    {
        $stored = 0;

        foreach (data_get($payload, 'entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $value = data_get($change, 'value', []);

                // Phone-number quality signal: pull the safety brake if we've been flagged.
                if (data_get($change, 'field') === 'phone_number_quality_update') {
                    $this->applyQualitySignal($board, $this->mapQualityEvent((string) data_get($value, 'event')));

                    continue;
                }

                // wa_id => profile name, so we can label the conversation/card.
                $contacts = collect(data_get($value, 'contacts', []))
                    ->mapWithKeys(fn ($c) => [(string) data_get($c, 'wa_id') => data_get($c, 'profile.name')]);

                foreach (data_get($value, 'messages', []) as $message) {
                    $this->ingestMessage($board, $message, $contacts);
                    $stored++;
                }

                foreach (data_get($value, 'statuses', []) as $status) {
                    $this->ingestStatus($status);
                }
            }
        }

        return $stored;
    }

    private function ingestMessage(Board $board, array $message, $contacts): void
    {
        $from = (string) data_get($message, 'from');
        if ($from === '') {
            return;
        }

        $conversation = $this->resolveConversation($board, $from, $contacts->get($from));

        // Refresh the 24h customer-service window on every inbound message.
        $conversation->last_inbound_at = now();
        $conversation->service_window_expires_at = now()->addDay();
        $conversation->save();

        $stored = WhatsappMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'wa_message_id' => data_get($message, 'id'),
            'type' => data_get($message, 'type', 'text'),
            'body' => $this->extractBody($message),
            'status' => 'received',
        ]);

        $this->broadcastMessage($board, $conversation, $stored, 'whatsapp.message.created');
    }

    private function ingestStatus(array $status): void
    {
        $waId = data_get($status, 'id');
        $state = data_get($status, 'status'); // sent | delivered | read | failed
        if (! $waId || ! $state) {
            return;
        }

        $message = WhatsappMessage::where('wa_message_id', $waId)->first();
        if (! $message) {
            return;
        }

        // Never regress a status (a late 'sent' must not overwrite 'read').
        if ($this->statusRank($state) < $this->statusRank($message->status)) {
            return;
        }

        $message->status = $state;
        if ($state === 'failed') {
            $message->error = (string) data_get($status, 'errors.0.title', 'Delivery failed');
        }
        $message->save();

        $conversation = $message->conversation()->with('board:id')->first();
        if ($conversation) {
            $this->broadcastMessage($conversation->board_id, $conversation, $message, 'whatsapp.message.updated');
        }
    }

    // ---------------------------------------------------------------------
    // Outbound — reply from a card, respecting the 24h window.
    // ---------------------------------------------------------------------

    /**
     * Send a free-form reply on a card's WhatsApp conversation. Only valid while the
     * 24h service window is open; otherwise the caller must send a template instead.
     *
     * @throws RuntimeException with a client-safe message on precondition failure.
     */
    public function replyToCard(Card $card, string $body, ?int $userId): WhatsappMessage
    {
        $conversation = $card->whatsappConversations()->first();
        if (! $conversation) {
            throw new RuntimeException('This card has no WhatsApp conversation yet.');
        }
        if (! $conversation->windowOpen()) {
            throw new RuntimeException('The 24-hour reply window has closed. Send an approved template instead.');
        }

        $board = $card->board;
        $result = $this->driverFor($board)->sendText($board, $conversation->wa_phone, $body);

        return $this->recordOutbound($board, $conversation, [
            'type' => 'text',
            'body' => $body,
        ], $result, $userId);
    }

    /**
     * Send a pre-approved template on a card's conversation (valid any time).
     *
     * @param  array<int,array<string,mixed>>  $components
     */
    public function sendTemplateToCard(Card $card, string $template, string $language, array $components, ?int $userId): WhatsappMessage
    {
        $conversation = $card->whatsappConversations()->first();
        if (! $conversation) {
            throw new RuntimeException('This card has no WhatsApp conversation yet.');
        }

        $board = $card->board;
        $result = $this->driverFor($board)->sendTemplate($board, $conversation->wa_phone, $template, $language, $components);

        return $this->recordOutbound($board, $conversation, [
            'type' => 'template',
            'body' => $this->summarizeComponents($template, $components),
            'template_name' => $template,
        ], $result, $userId);
    }

    // ---------------------------------------------------------------------
    // Notification channel (card #22) — deliver a Yondra alert to a user's number.
    // ---------------------------------------------------------------------

    /**
     * Send an approved template to an arbitrary number as a notification (not tied to
     * a customer conversation, so nothing is recorded). Uses the related board's creds
     * when known, else the instance-wide config fallback via an empty board.
     *
     * @param  array<int,array<string,mixed>>  $components
     */
    public function sendNotificationTemplate(?int $boardId, string $to, string $template, string $language = 'en', array $components = []): SendResult
    {
        $board = ($boardId ? Board::find($boardId) : null) ?: new Board;

        return $this->driverFor($board)->sendTemplate($board, $to, $template, $language, $components);
    }

    // ---------------------------------------------------------------------
    // Stage automations + quality safety brake (card #58).
    // ---------------------------------------------------------------------

    /**
     * Run the configured automation for a card that just entered a section. All the
     * anti-block guardrails from card #57 live here: only opted-in contacts (a
     * conversation must exist), only an approved template, and never while quality
     * has dropped.
     */
    public function runStageAutomation(int $cardId, int $sectionId): void
    {
        $card = Card::with('board')->find($cardId);
        // Ignore if the card moved on again before the job ran.
        if (! $card || (int) $card->section_id !== $sectionId) {
            return;
        }

        $automation = WhatsappStageAutomation::where('board_id', $card->board_id)
            ->where('section_id', $sectionId)
            ->first();
        if (! $automation || ! $automation->isActive()) {
            return;
        }

        // Only message an existing (opted-in) contact — never cold-start from a stage move.
        $conversation = $card->whatsappConversations()->first();
        if (! $conversation) {
            return;
        }

        // Safety: never send while this number's quality is degraded.
        if (in_array($conversation->quality_state, ['yellow', 'red'], true)) {
            return;
        }

        $this->sendTemplateToCard($card, $automation->template_name, $automation->language, [], null);
    }

    /**
     * Apply a quality signal to a board: stamp its conversations and, on a
     * yellow/red drop, pause every stage automation so we stop sending until a human
     * clears it.
     */
    public function applyQualitySignal(Board $board, string $state): void
    {
        WhatsappConversation::where('board_id', $board->id)->update(['quality_state' => $state]);

        if (in_array($state, ['yellow', 'red'], true)) {
            WhatsappStageAutomation::where('board_id', $board->id)
                ->whereNull('paused_at')
                ->update(['paused_at' => now()]);
        }
    }

    private function mapQualityEvent(string $event): string
    {
        $event = strtoupper($event);

        return match (true) {
            str_contains($event, 'FLAG') => 'red',
            str_contains($event, 'WARN'), str_contains($event, 'LOW') => 'yellow',
            default => 'green',
        };
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function recordOutbound(Board $board, WhatsappConversation $conversation, array $attrs, SendResult $result, ?int $userId): WhatsappMessage
    {
        $message = WhatsappMessage::create(array_merge([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'wa_message_id' => $result->waMessageId,
            'status' => $result->ok ? 'sent' : 'failed',
            'error' => $result->error,
            'sent_by_user_id' => $userId,
        ], $attrs));

        $this->broadcastMessage($board, $conversation, $message, 'whatsapp.message.created');

        return $message;
    }

    /** Find the (board, phone) conversation or create it — auto-spawning a lead card. */
    private function resolveConversation(Board $board, string $phone, ?string $name): WhatsappConversation
    {
        $conversation = WhatsappConversation::where('board_id', $board->id)
            ->where('wa_phone', $phone)
            ->first();

        if ($conversation) {
            if ($name && $conversation->contact_name !== $name) {
                $conversation->contact_name = $name;
                $conversation->save();
            }

            return $conversation;
        }

        // First time we hear from this number: give it a named lead card so the team
        // never again hunts "a loose number with no name" (card #54).
        $card = $this->createLeadCard($board, $name ?: $phone);
        broadcast(new BoardEvent($board->id, 'card.created', $card));

        return WhatsappConversation::create([
            'board_id' => $board->id,
            'card_id' => $card['id'],
            'wa_phone' => $phone,
            'contact_name' => $name,
        ]);
    }

    /**
     * Create a lead card in the board's first section. Mirrors CardModelRepository::save
     * (ticket-number locking, positioning) but attributes authorship to the board owner
     * since a webhook has no authenticated user.
     */
    private function createLeadCard(Board $board, string $name): array
    {
        return DB::transaction(function () use ($board, $name) {
            $locked = Board::whereKey($board->id)->lockForUpdate()->firstOrFail();
            $ticket = $locked->next_ticket_number;
            $locked->increment('next_ticket_number');

            $section = Section::where('board_id', $locked->id)->orderBy('order')->firstOrFail();
            $position = (int) Card::where('section_id', $section->id)->max('position') + 1;

            $card = Card::create([
                'board_id' => $locked->id,
                'section_id' => $section->id,
                'created_by_user_id' => $locked->user_id,
                'name' => $name,
                'description' => '',
                'position' => $position,
                'ticket_number' => $ticket,
                'section_entered_at' => now(),
            ]);

            $card->ticket_key = Card::ticketKey($locked->ticket_prefix, $ticket);

            return $card->load(['assignedUser:id,name', 'createdBy:id,name', 'tags', 'images', 'links', 'documents'])->toArray();
        });
    }

    private function broadcastMessage(Board|int $board, WhatsappConversation $conversation, WhatsappMessage $message, string $type): void
    {
        $boardId = $board instanceof Board ? $board->id : $board;
        broadcast(new BoardEvent($boardId, $type, [
            'card_id' => $conversation->card_id,
            'conversation' => ['id' => $conversation->id, 'wa_phone' => $conversation->wa_phone, 'contact_name' => $conversation->contact_name],
            'message' => $message->toArray(),
        ]));
    }

    /** Pull a human-readable body out of the various inbound message shapes. */
    private function extractBody(array $message): ?string
    {
        return match (data_get($message, 'type')) {
            'text' => data_get($message, 'text.body'),
            'button' => data_get($message, 'button.text'),
            'interactive' => data_get($message, 'interactive.button_reply.title')
                             ?? data_get($message, 'interactive.list_reply.title'),
            'image', 'video', 'document', 'audio' => data_get($message, data_get($message, 'type').'.caption')
                             ?? '['.data_get($message, 'type').']',
            default => null,
        };
    }

    private function summarizeComponents(string $template, array $components): string
    {
        $params = collect($components)
            ->flatMap(fn ($c) => data_get($c, 'parameters', []))
            ->map(fn ($p) => data_get($p, 'text'))
            ->filter()
            ->implode(', ');

        return $params !== '' ? "[template: {$template}] {$params}" : "[template: {$template}]";
    }

    private function statusRank(?string $status): int
    {
        return match ($status) {
            'read' => 4,
            'delivered' => 3,
            'sent' => 2,
            'received' => 2,
            'failed' => 1,
            default => 0,
        };
    }
}
