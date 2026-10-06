<?php

namespace App\Services;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardActivity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes a card's History tab (card_activities). Most entries come from model
 * observers (CardObserver, CardRelatedObserver); a few are explicit (tag sync,
 * contact upsert) because Eloquent can't see them.
 *
 * - Source: who/what acted. Defaults to 'user' when authenticated, 'automation'
 *   otherwise; webhooks/CI/import/planning tag themselves via as() or the
 *   `card.history` route middleware.
 * - Entries are written immediately, inside the caller's transaction: a rolled-back
 *   change leaves no history, and a failure after commit can't lose it.
 * - Batch (one request = one action): within batch(), further card.updated records
 *   for a card merge into its existing row, and are skipped for a card created in
 *   the same batch (they're just its initial values). Live-update nudges are
 *   coalesced into one per board, sent when the batch ends.
 */
class CardHistory
{
    /** Cap for long text snapshots (description, comment edits). */
    public const TEXT_CAP = 10000;

    private static ?string $source = null;

    private static ?string $via = null;

    /**
     * Open batch scope, or null outside one.
     *
     * @var array{created: array<int, true>, updated: array<int, int>, nudge: array<int, array<int, true>>}|null
     */
    private static ?array $scope = null;

    /** Run $fn with history attributed to $source (e.g. 'webhook', via 'whatsapp'). */
    public static function as(string $source, callable $fn, ?string $via = null): mixed
    {
        [$prevSource, $prevVia] = [self::$source, self::$via];
        self::$source = $source;
        self::$via = $via;
        try {
            return $fn();
        } finally {
            self::$source = $prevSource;
            self::$via = $prevVia;
        }
    }

    /** Treat everything recorded inside $fn as one action (see class doc). */
    public static function batch(callable $fn): mixed
    {
        if (self::$scope !== null) {
            return $fn(); // already batching — the outer batch owns the scope
        }

        self::$scope = ['created' => [], 'updated' => [], 'nudge' => []];
        try {
            return $fn();
        } finally {
            $nudge = self::$scope['nudge'];
            self::$scope = null;
            foreach ($nudge as $boardId => $cardIds) {
                self::nudge($boardId, array_keys($cardIds));
            }
        }
    }

    public static function record(Card $card, string $type, array $changes = [], array $meta = []): void
    {
        if (self::$via !== null) {
            $meta['via'] ??= self::$via;
        }

        $cardId = (int) $card->id;
        $boardId = (int) $card->board_id;
        $scope = &self::$scope;

        if ($scope !== null && $type === 'card.updated') {
            if (isset($scope['created'][$cardId])) {
                return;
            }
            if (isset($scope['updated'][$cardId]) && self::mergeInto($scope['updated'][$cardId], $changes, $meta)) {
                return;
            }
        }

        $entry = CardActivity::create([
            'card_id' => $cardId,
            'board_id' => $boardId,
            'user_id' => Auth::id(),
            'source' => self::$source ?? (Auth::check() ? 'user' : 'automation'),
            'type' => $type,
            'changes' => $changes ?: null,
            'meta' => $meta ?: null,
        ]);

        if ($scope === null) {
            self::nudge($boardId, [$cardId]);

            return;
        }

        if ($type === 'card.created') {
            $scope['created'][$cardId] = true;
        } elseif ($type === 'card.updated') {
            $scope['updated'][$cardId] = $entry->id;
        }
        $scope['nudge'][$boardId][$cardId] = true;
    }

    /** Trim long text for a snapshot; null/'' stay as-is. */
    public static function clip(?string $text): ?string
    {
        if ($text === null || mb_strlen($text) <= self::TEXT_CAP) {
            return $text;
        }

        return mb_substr($text, 0, self::TEXT_CAP).'…';
    }

    /** API shape for one entry. */
    public static function serialize(CardActivity $a): array
    {
        return [
            'id' => $a->id,
            'card_id' => $a->card_id,
            'type' => $a->type,
            'source' => $a->source,
            'changes' => $a->changes ?? (object) [],
            'meta' => $a->meta ?? (object) [],
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /** False when the row is gone (its transaction rolled back) — caller writes anew. */
    private static function mergeInto(int $activityId, array $changes, array $meta): bool
    {
        $row = CardActivity::find($activityId);
        if (! $row) {
            return false;
        }

        $row->update([
            'changes' => array_merge($row->changes ?? [], $changes) ?: null,
            'meta' => array_merge($row->meta ?? [], $meta) ?: null,
        ]);

        return true;
    }

    /**
     * Lightweight live-update nudge: an open History tab refetches. Full entries can
     * carry two 10KB description snapshots — over Pusher's 10KB message cap. Sent
     * after commit and best-effort: a socket hiccup must never fail the change itself.
     */
    private static function nudge(int $boardId, array $cardIds): void
    {
        DB::afterCommit(function () use ($boardId, $cardIds) {
            try {
                broadcast(new BoardEvent($boardId, 'card.activity', ['card_ids' => $cardIds]));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
