<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Models\User;

/**
 * Owns the notification-preferences matrix: the catalog of event types and
 * channels, their defaults, and how a user's stored overrides merge on top.
 *
 * Storage is a JSON column on `users` shaped { event_type: { channel: bool } };
 * anything missing falls back to {@see defaults()}, so new event types/channels
 * are opt-in-by-default without a data migration.
 */
class NotificationPreferenceService
{
    /** Channels a notification can travel through. `in_app` covers the live bell + toast. */
    public const CHANNELS = [
        ['key' => 'in_app',   'label' => 'In-app'],
        ['key' => 'email',    'label' => 'Email'],
        ['key' => 'push',     'label' => 'Mobile push'],
        ['key' => 'whatsapp', 'label' => 'WhatsApp'],
    ];

    /**
     * Event categories shown in the settings matrix. `active` marks the ones a
     * trigger already fires today (the rest are wired in a later phase but shown
     * so users can set them ahead of time).
     */
    public const EVENT_TYPES = [
        ['key' => 'assignment',  'label' => 'Assigned to a card',   'description' => 'Someone assigns a card to you',            'active' => true],
        ['key' => 'mention',     'label' => 'Mentioned (@you)',     'description' => 'Someone @mentions you in a comment or chat', 'active' => true],
        ['key' => 'comment',     'label' => 'Comment on your card', 'description' => 'A new comment on a card you own or are assigned to', 'active' => true],
        ['key' => 'card_status', 'label' => 'Card moved / done',    'description' => 'A card assigned to you changes column or is completed', 'active' => true],
        ['key' => 'due_date',    'label' => 'Due-date reminder',    'description' => 'A card you are assigned to is due soon',    'active' => true],
        ['key' => 'sharing',     'label' => 'Board / project invite', 'description' => 'You are added to a board or project',     'active' => true],
        ['key' => 'chat',        'label' => 'Board chat',           'description' => 'New messages in a board you belong to',     'active' => true],
        ['key' => 'lead_dropped', 'label' => 'Lead dropped out',    'description' => 'A lead was auto-dropped after no reply to re-engagement', 'active' => true],
        ['key' => 'qa_sprint',   'label' => 'QA / sprint / planning', 'description' => 'Bugs filed, sprints started, planning updates', 'active' => false],
    ];

    /** Default channel state per event type. */
    public function defaults(): array
    {
        // `whatsapp` defaults off everywhere: it's opt-in (costs money, needs an
        // approved template + the user's number).
        return [
            'assignment' => ['in_app' => true, 'email' => true,  'push' => true,  'whatsapp' => false],
            'mention' => ['in_app' => true, 'email' => true,  'push' => true,  'whatsapp' => false],
            'comment' => ['in_app' => true, 'email' => false, 'push' => false, 'whatsapp' => false],
            'card_status' => ['in_app' => true, 'email' => false, 'push' => false, 'whatsapp' => false],
            'due_date' => ['in_app' => true, 'email' => true,  'push' => true,  'whatsapp' => false],
            'sharing' => ['in_app' => true, 'email' => true,  'push' => false, 'whatsapp' => false],
            'chat' => ['in_app' => true, 'email' => false, 'push' => false, 'whatsapp' => false],
            'lead_dropped' => ['in_app' => true, 'email' => true, 'push' => true, 'whatsapp' => false],
            'qa_sprint' => ['in_app' => true, 'email' => false, 'push' => false, 'whatsapp' => false],
        ];
    }

    /** The fully-resolved matrix for a user (defaults overlaid with their overrides). */
    public function resolve(User $user): array
    {
        $stored = $user->notification_preferences ?? [];
        $resolved = $this->defaults();

        foreach ($resolved as $event => $channels) {
            foreach ($channels as $channel => $default) {
                $override = $stored[$event][$channel] ?? null;
                if (is_bool($override)) {
                    $resolved[$event][$channel] = $override;
                }
            }
        }

        return $resolved;
    }

    /** Enabled channel keys for one event type, e.g. ['in_app', 'email']. */
    public function channels(User $user, string $eventType): array
    {
        $resolved = $this->resolve($user);
        $row = $resolved[$eventType] ?? [];

        return array_keys(array_filter($row, fn ($enabled) => $enabled === true));
    }

    /**
     * Sanitize arbitrary client input down to known events/channels and booleans,
     * so a PUT can only ever set legitimate matrix cells.
     */
    public function sanitize(array $input): array
    {
        $clean = [];
        $defaults = $this->defaults();

        foreach ($defaults as $event => $channels) {
            foreach ($channels as $channel => $_) {
                if (isset($input[$event][$channel])) {
                    $clean[$event][$channel] = (bool) $input[$event][$channel];
                }
            }
        }

        return $clean;
    }

    /** Catalog for the settings UI: event types, channels, and the user's resolved matrix. */
    public function catalog(User $user): array
    {
        return [
            'event_types' => self::EVENT_TYPES,
            'channels' => self::CHANNELS,
            'preferences' => $this->resolve($user),
        ];
    }
}
