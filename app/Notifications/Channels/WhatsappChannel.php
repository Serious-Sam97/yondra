<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Jobs\SendWhatsappNotificationJob;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification channel that delivers via WhatsApp. Registered by
 * BaseYondraNotification::via() when the recipient has opted in and set a number.
 * The actual send is queued so it never blocks the triggering request.
 */
class WhatsappChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable->whatsapp_number ?? null;
        if (! $to || ! method_exists($notification, 'toWhatsapp')) {
            return;
        }

        $data = $notification->toWhatsapp($notifiable);
        if (empty($data['template'])) {
            return;
        }

        SendWhatsappNotificationJob::dispatch(
            $data['board_id'] ?? null,
            (string) $to,
            (string) $data['template'],
            (string) ($data['language'] ?? 'en'),
            $data['components'] ?? [],
        );
    }
}
