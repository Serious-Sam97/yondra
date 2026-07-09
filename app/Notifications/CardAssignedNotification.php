<?php

declare(strict_types=1);

namespace App\Notifications;

class CardAssignedNotification extends BaseYondraNotification
{
    public function __construct(
        public int $actorId,
        public string $actorName,
        public int $boardId,
        public int $cardId,
        public string $cardName,
    ) {}

    public function eventType(): string
    {
        return 'assignment';
    }

    public function toPayload(): array
    {
        return [
            'type' => 'card.assigned',
            'message' => $this->actorName.' assigned you to "'.$this->cardName.'"',
            'board_id' => $this->boardId,
            'card_id' => $this->cardId,
            'deep_link' => '/boards/'.$this->boardId.'?card='.$this->cardId,
        ];
    }
}
