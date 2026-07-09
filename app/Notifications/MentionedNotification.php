<?php

declare(strict_types=1);

namespace App\Notifications;

class MentionedNotification extends BaseYondraNotification
{
    /**
     * @param  string  $message  Pre-rendered by the caller (context differs between
     *                           a card comment and board chat).
     */
    public function __construct(
        public int $actorId,
        public string $message,
        public ?int $boardId,
        public ?int $cardId,
        public ?string $deepLink = null,
    ) {}

    public function eventType(): string
    {
        return 'mention';
    }

    public function toPayload(): array
    {
        $deepLink = $this->deepLink ?? ($this->boardId
            ? '/boards/'.$this->boardId.($this->cardId ? '?card='.$this->cardId : '')
            : null);

        return [
            'type' => 'mention',
            'message' => $this->message,
            'board_id' => $this->boardId,
            'card_id' => $this->cardId,
            'deep_link' => $deepLink,
        ];
    }
}
