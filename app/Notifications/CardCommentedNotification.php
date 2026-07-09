<?php

declare(strict_types=1);

namespace App\Notifications;

class CardCommentedNotification extends BaseYondraNotification
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
        return 'comment';
    }

    public function toPayload(): array
    {
        return [
            'type' => 'card.commented',
            'message' => $this->actorName.' commented on "'.$this->cardName.'"',
            'board_id' => $this->boardId,
            'card_id' => $this->cardId,
            'deep_link' => '/boards/'.$this->boardId.'?card='.$this->cardId,
        ];
    }
}
