<?php

declare(strict_types=1);

namespace App\Notifications;

class BoardSharedNotification extends BaseYondraNotification
{
    public function __construct(
        public int $actorId,
        public string $actorName,
        public int $boardId,
        public string $boardName,
    ) {}

    public function eventType(): string
    {
        return 'sharing';
    }

    public function toPayload(): array
    {
        return [
            'type' => 'board.shared',
            'message' => $this->actorName.' shared "'.$this->boardName.'" with you',
            'board_id' => $this->boardId,
            'card_id' => null,
            'deep_link' => '/boards/'.$this->boardId,
        ];
    }
}
