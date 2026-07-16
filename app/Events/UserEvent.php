<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic typed frame on a user's private channel — the user-scoped twin of
 * {@see BoardEvent}. Rides the already-authorized `App.Models.User.{id}` channel
 * (the one notifications use) so no new channel auth is needed. Carries
 * `{type, payload}` under a single `.user.event` name; consumers route on
 * `payload.scope`, exactly like `.board.event` frames.
 */
class UserEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $type,
        public readonly array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'user.event';
    }
}
