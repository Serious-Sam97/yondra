<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// Yutopia's phone, live: a text, a read receipt, someone typing, a reaction,
// a thread that changed — pushed to each member on their own private channel
// (the phone listens to ".phone" there; yutopia world/phone/index.ts).
class YutopiaPhoneEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'phone';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
