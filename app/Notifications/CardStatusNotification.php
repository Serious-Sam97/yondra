<?php

declare(strict_types=1);

namespace App\Notifications;

class CardStatusNotification extends BaseYondraNotification
{
    public function __construct(
        public int $actorId,
        public string $actorName,
        public int $boardId,
        public int $cardId,
        public string $cardName,
        public string $sectionName,
        public bool $done,
    ) {}

    public function eventType(): string
    {
        return 'card_status';
    }

    public function toPayload(): array
    {
        $message = $this->done
            ? $this->actorName.' completed "'.$this->cardName.'"'
            : $this->actorName.' moved "'.$this->cardName.'" to '.$this->sectionName;

        return [
            'type' => 'card.status',
            'message' => $message,
            'board_id' => $this->boardId,
            'card_id' => $this->cardId,
            'deep_link' => '/boards/'.$this->boardId.'?card='.$this->cardId,
        ];
    }
}
