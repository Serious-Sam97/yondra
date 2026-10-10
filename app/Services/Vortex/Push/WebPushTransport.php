<?php

declare(strict_types=1);

namespace App\Services\Vortex\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Q-03 · the Web Push protocol (VAPID), via minishlink/web-push. */
final class WebPushTransport implements PushTransport
{
    public function send(array $subscriptions, string $payload): array
    {
        $push = new WebPush(['VAPID' => [
            'subject' => (string) config('vortex_mk5.push.subject'),
            'publicKey' => (string) config('vortex_mk5.push.public_key'),
            'privateKey' => (string) config('vortex_mk5.push.private_key'),
        ]], ['TTL' => 3600, 'urgency' => 'normal']);
        foreach ($subscriptions as $s) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $s['endpoint'],
                'publicKey' => $s['p256dh'],
                'authToken' => $s['auth'],
                'contentEncoding' => 'aes128gcm',
            ]), $payload);
        }
        $gone = [];
        foreach ($push->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $gone[] = $report->getEndpoint();
            }
        }

        return $gone;
    }
}
