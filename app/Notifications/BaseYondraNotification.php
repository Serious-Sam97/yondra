<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\NotificationPreferenceService;
use App\Services\Notifier;
use Illuminate\Notifications\Notification;

/**
 * Base for every Yondra notification. Concrete classes describe a single event
 * (assignment, comment, mention, …) via {@see toPayload()} and expose the acting
 * user's id as a public `$actorId` property so {@see Notifier} can
 * skip self-notification.
 *
 * For now the only delivery channel is `database` (the in-app bell). Email and
 * push are added later by widening {@see via()} to read user preferences.
 */
abstract class BaseYondraNotification extends Notification
{
    /**
     * Deliver on the channels the recipient has enabled for this event type.
     * `in_app` maps to the `database` channel (persisted bell); the live
     * broadcast is fired separately by {@see Notifier}, gated on
     * {@see wantsInApp()}. `email`/`push` are added as those channels ship.
     */
    public function via(object $notifiable): array
    {
        $channels = $this->enabledChannels($notifiable);
        $via = [];

        if (in_array('in_app', $channels, true)) {
            $via[] = 'database';
        }

        return $via;
    }

    /** Whether the recipient wants the in-app (bell + live toast) channel. */
    public function wantsInApp(object $notifiable): bool
    {
        return in_array('in_app', $this->enabledChannels($notifiable), true);
    }

    /** Whether the recipient wants the email channel for this event type. */
    public function wantsEmail(object $notifiable): bool
    {
        return in_array('email', $this->enabledChannels($notifiable), true);
    }

    /** Email subject line. Subclasses may override for something more specific. */
    public function mailSubject(): string
    {
        return 'Yondra · ' . $this->mailEyebrow();
    }

    /** Short uppercase-ish label shown in the email header / eyebrow. */
    public function mailEyebrow(): string
    {
        return match ($this->eventType()) {
            'assignment'  => 'Assignment',
            'mention'     => 'Mention',
            'comment'     => 'New comment',
            'card_status' => 'Card update',
            'due_date'    => 'Due soon',
            'sharing'     => 'Invite',
            'chat'        => 'Board chat',
            default       => 'Notification',
        };
    }

    /** @return list<string> enabled channel keys for this event type */
    protected function enabledChannels(object $notifiable): array
    {
        return resolve(NotificationPreferenceService::class)
            ->channels($notifiable, $this->eventType());
    }

    /** The preference category this notification belongs to. */
    abstract public function eventType(): string;

    public function toDatabase(object $notifiable): array
    {
        return $this->toPayload();
    }

    public function toArray(object $notifiable): array
    {
        return $this->toPayload();
    }

    /**
     * The channel-agnostic payload: persisted to the bell and broadcast live.
     *
     * @return array{type:string,message:string,board_id:?int,card_id:?int,deep_link:?string}
     */
    abstract public function toPayload(): array;
}
