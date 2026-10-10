<?php

declare(strict_types=1);

namespace App\Notifications;

/** O-06 · someone on your team dedicated something to you on the ghost radio. */
class RadioDedicationNotification extends BaseYondraNotification
{
    public function __construct(
        public int $actorId,
        public string $actorName,
    ) {}

    public function eventType(): string
    {
        return 'radio';
    }

    public function toPayload(): array
    {
        return [
            'type' => 'radio.dedication',
            'message' => $this->actorName.' dedicated something to you on 03.13 · tune in',
            'board_id' => null,
            'card_id' => null,
            'deep_link' => '/dashboard#radio',
        ];
    }
}
