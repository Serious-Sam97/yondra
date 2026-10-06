<?php

namespace App\Observers;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Sprint;
use App\Infrastructure\Models\User;
use App\Services\CardHistory;

/**
 * History for the card's own fields. Fires on every save path (controllers, drag
 * reorder, webhooks, jobs), so no controller has to remember to log.
 */
class CardObserver
{
    /**
     * Fields that show in history. Everything else (position, timestamps, SLA stamps,
     * reminder flags, done_at/lost_at, amount_paid) is derived or noise.
     */
    private const TRACKED = [
        'name', 'description', 'section_id', 'assigned_user_id', 'due_date', 'priority',
        'value', 'story_points', 'sprint_id', 'loss_reason', 'is_done',
    ];

    public function created(Card $card): void
    {
        CardHistory::record($card, 'card.created', [], [
            'name' => $card->name,
            'section' => $this->sectionName($card->section_id),
        ]);

        if ($card->parent_card_id && ($parent = Card::find($card->parent_card_id))) {
            CardHistory::record($parent, 'subtask.added', [], ['subtask_id' => $card->id, 'name' => $card->name]);
        }
    }

    public function updated(Card $card): void
    {
        $changes = [];
        foreach (array_intersect(array_keys($card->getChanges()), self::TRACKED) as $field) {
            $changes[$field] = [
                'from' => $this->present($field, $card->getRawOriginal($field)),
                'to' => $this->present($field, $card->getAttributes()[$field] ?? null),
            ];
        }

        $archivedChanged = $card->wasChanged('archived_at');
        if ($archivedChanged) {
            $type = $card->archived_at ? 'card.archived' : 'card.restored';
            CardHistory::record($card, $type, $changes);
        } elseif ($changes) {
            CardHistory::record($card, 'card.updated', $changes);
        }

        $this->rollupToParent($card, $archivedChanged);
    }

    /** Subtask lifecycle also shows on its epic's history. */
    private function rollupToParent(Card $card, bool $archivedChanged): void
    {
        if (! $card->parent_card_id) {
            return;
        }

        $type = null;
        if ($archivedChanged && $card->archived_at) {
            $type = 'subtask.removed';
        } elseif ($card->wasChanged('is_done') || $card->wasChanged('done_at')) {
            $wasDone = (bool) $card->getRawOriginal('is_done') || $card->getRawOriginal('done_at') !== null;
            $isDone = (bool) $card->is_done || $card->done_at !== null;
            if ($wasDone !== $isDone) {
                $type = $isDone ? 'subtask.completed' : 'subtask.reopened';
            }
        }

        if ($type && ($parent = Card::find($card->parent_card_id))) {
            CardHistory::record($parent, $type, [], ['subtask_id' => $card->id, 'name' => $card->name]);
        }
    }

    /** Snapshot a value as the user should read it later (names, not ids). */
    private function present(string $field, mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return match ($field) {
            'section_id' => $this->sectionName($raw),
            'assigned_user_id' => User::whereKey($raw)->value('name'),
            'sprint_id' => Sprint::whereKey($raw)->value('name'),
            'due_date' => substr((string) $raw, 0, 10),
            'value' => (float) $raw,
            'story_points' => (int) $raw,
            'is_done' => (bool) $raw,
            'description' => CardHistory::clip((string) $raw),
            default => $raw,
        };
    }

    private function sectionName(mixed $id): ?string
    {
        return $id ? Section::whereKey($id)->value('name') : null;
    }
}
