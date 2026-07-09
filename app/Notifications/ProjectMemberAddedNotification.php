<?php

declare(strict_types=1);

namespace App\Notifications;

class ProjectMemberAddedNotification extends BaseYondraNotification
{
    public function __construct(
        public int $actorId,
        public string $actorName,
        public int $projectId,
        public string $projectName,
    ) {}

    public function eventType(): string
    {
        return 'sharing';
    }

    public function toPayload(): array
    {
        return [
            'type' => 'project.member_added',
            'message' => $this->actorName.' added you to "'.$this->projectName.'"',
            'board_id' => null,
            'card_id' => null,
            'deep_link' => '/projects/'.$this->projectId,
        ];
    }
}
