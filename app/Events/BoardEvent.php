<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BoardEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $boardId,
        public readonly string $type,
        public readonly array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.' . $this->boardId)];
    }

    public function broadcastAs(): string
    {
        return 'board.event';
    }
}
