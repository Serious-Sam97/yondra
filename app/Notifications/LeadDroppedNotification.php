<?php

declare(strict_types=1);

namespace App\Notifications;

class LeadDroppedNotification extends BaseYondraNotification
{
    /** No actor — the re-engagement sweep retired this lead automatically. */
    public function __construct(
        public int $boardId,
        public int $cardId,
        public string $cardName,
    ) {}

    public function eventType(): string
    {
        return 'lead_dropped';
    }

    public function toPayload(): array
    {
        return [
            'type' => 'lead.dropped',
            'message' => '"'.$this->cardName.'" dropped out — no reply after re-engagement',
            'board_id' => $this->boardId,
            'card_id' => $this->cardId,
            'deep_link' => '/boards/'.$this->boardId.'?card='.$this->cardId,
        ];
    }
}
