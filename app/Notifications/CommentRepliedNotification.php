<?php

declare(strict_types=1);

namespace App\Notifications;

class CommentRepliedNotification extends BaseYondraNotification
{
    public function __construct(
        public int $actorId,
        public string $actorName,
        public int $boardId,
        public int $cardId,
        public string $cardName,
    ) {}

    // Shares the 'comment' preference bucket — a reply IS a comment to the reader.
    public function eventType(): string
    {
        return 'comment';
    }

    public function toPayload(): array
    {
        return [
            'type' => 'comment.replied',
            'message' => $this->actorName.' replied in a thread on "'.$this->cardName.'"',
            'board_id' => $this->boardId,
            'card_id' => $this->cardId,
            'deep_link' => '/boards/'.$this->boardId.'?card='.$this->cardId,
        ];
    }
}
