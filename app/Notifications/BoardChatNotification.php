<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * A collapsed "you have new board chat" ping. Only sent when the recipient has
 * no unread chat notification for the board yet (see BoardMessageController), so
 * a busy chat produces one bell entry, not one per message.
 */
class BoardChatNotification extends BaseYondraNotification
{
    public function __construct(
        public int $actorId,
        public int $boardId,
        public string $boardName,
    ) {}

    public function eventType(): string
    {
        return 'chat';
    }

    public function toPayload(): array
    {
        return [
            'type' => 'board.chat',
            'message' => 'New messages in "'.$this->boardName.'"',
            'board_id' => $this->boardId,
            'card_id' => null,
            'deep_link' => '/boards/'.$this->boardId,
        ];
    }
}
