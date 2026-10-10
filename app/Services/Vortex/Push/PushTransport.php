<?php

declare(strict_types=1);

namespace App\Services\Vortex\Push;

/** Q-03 · how a push leaves the server (the real one, or a fake in tests). */
interface PushTransport
{
    /**
     * @param  list<array{endpoint:string,p256dh:string,auth:string}>  $subscriptions
     * @return list<string> endpoints the push service says are gone (to forget)
     */
    public function send(array $subscriptions, string $payload): array;
}
