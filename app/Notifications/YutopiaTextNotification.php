<?php

declare(strict_types=1);

namespace App\Notifications;

/** A text on Yutopia's phone that's been waiting for you a while. */
class YutopiaTextNotification extends BaseYondraNotification
{
    public function __construct(
        public int $actorId,
        public string $actorName,
        public string $preview,
    ) {}

    public function eventType(): string
    {
        return 'yutopia';
    }

    public function toPayload(): array
    {
        return [
            'type' => 'yutopia.text',
            'message' => $this->actorName.' texted you in Yutopia: '.$this->preview,
            'board_id' => null,
            'card_id' => null,
            'deep_link' => rtrim((string) config('yutopia.client_url'), '/'),
        ];
    }
}
