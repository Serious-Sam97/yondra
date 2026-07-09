<?php

declare(strict_types=1);

namespace App\Notifications;

class DueDateReminderNotification extends BaseYondraNotification
{
    /** No actor — this is a system reminder. */
    public function __construct(
        public int $boardId,
        public int $cardId,
        public string $cardName,
        public string $when,
    ) {}

    public function eventType(): string
    {
        return 'due_date';
    }

    public function toPayload(): array
    {
        return [
            'type' => 'card.due',
            'message' => '"'.$this->cardName.'" is due '.$this->when,
            'board_id' => $this->boardId,
            'card_id' => $this->cardId,
            'deep_link' => '/boards/'.$this->boardId.'?card='.$this->cardId,
        ];
    }
}
