<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\UserNotificationEvent;
use App\Infrastructure\Models\User;
use App\Mail\NotificationMail;
use App\Notifications\BaseYondraNotification;
use Illuminate\Support\Facades\Mail;

/**
 * Single fan-out point for notifications: persists to the in-app bell, pushes it
 * live, and queues an email — each gated on the recipient's per-event channel
 * preferences. Skips the acting user so nobody is notified of their own action.
 */
class Notifier
{
    /**
     * @param  User|iterable<User>  $recipients
     */
    public function send(User|iterable $recipients, BaseYondraNotification $notification): void
    {
        $list = $recipients instanceof User ? [$recipients] : $recipients;
        $actorId = property_exists($notification, 'actorId') ? (int) $notification->actorId : null;

        foreach ($list as $user) {
            if (! $user) {
                continue;
            }
            if ($actorId !== null && (int) $user->id === $actorId) {
                continue;
            }

            // `notify()` persists only the channels via() returns (respects prefs);
            // the live push is gated on the same in-app preference.
            $user->notify($notification);
            if ($notification->wantsInApp($user)) {
                broadcast(new UserNotificationEvent((int) $user->id, $notification->toPayload()));
            }

            // Email is queued (never blocks the request) when the recipient wants it.
            if ($user->email && $notification->wantsEmail($user)) {
                $this->queueEmail($user, $notification);
            }
        }
    }

    private function queueEmail(User $user, BaseYondraNotification $notification): void
    {
        $payload = $notification->toPayload();
        $link = $payload['deep_link'] ?? null;
        $url = $link ? rtrim((string) config('app.frontend_url'), '/').$link : null;

        Mail::to($user->email)->queue(new NotificationMail(
            subjectLine: $notification->mailSubject(),
            eyebrow: $notification->mailEyebrow(),
            heading: $payload['message'],
            actionUrl: $url,
        ));
    }
}
