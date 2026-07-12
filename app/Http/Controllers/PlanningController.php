<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\PlanningSession;
use App\Infrastructure\Models\PlanningVote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlanningController extends Controller
{
    // Decks a session can be dealt with ('?' = "no idea", '☕' = "need a break").
    // The deck is fixed when the session is created. Only integer values can be
    // applied to story points (unsignedSmallInteger on cards).
    private const DECKS = [
        'fib' => ['1', '2', '3', '5', '8', '13', '21', '?'],
        'fib-x' => ['0', '0.5', '1', '2', '3', '5', '8', '13', '21', '40', '100', '?', '☕'],
        'tshirt' => ['XS', 'S', 'M', 'L', 'XL', '?'],
    ];

    // Participants ping every ~25s; a joiner silent for longer than this who has
    // NOT cast a vote is considered gone (a cast vote always stands).
    private const PRESENCE_TTL_SECONDS = 75;

    public function show(int $boardId, int $cardId)
    {
        $board = $this->authorizeBoard($boardId);
        $this->boardCard($boardId, $cardId);
        if ($board->type !== 'scrum') {
            return response()->noContent();
        }

        $session = PlanningSession::where('card_id', $cardId)->first();
        if ($session) {
            $this->settleTimer($session);
        }
        if ($session && $this->pruneStale($session)) {
            $session = null;
        }

        // 204 (not json(null)) — Symfony's JsonResponse encodes null as "{}".
        return $session
            ? response()->json($this->snapshot($session, Auth::id()))
            : response()->noContent();
    }

    public function join(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeScrumBoard($boardId);
        $this->boardCard($boardId, $cardId);
        $validated = $request->validate([
            'deck' => ['sometimes', Rule::in(array_keys(self::DECKS))],
            'spectator' => ['sometimes', 'boolean'],
        ]);

        $session = DB::transaction(function () use ($boardId, $cardId, $validated) {
            // The deck is only honored at creation — a live table keeps its cards.
            $session = PlanningSession::firstOrCreate(
                ['card_id' => $cardId],
                [
                    'board_id' => $boardId,
                    'round' => 1,
                    'revealed' => false,
                    'started_by_user_id' => Auth::id(),
                    'deck' => $validated['deck'] ?? 'fib',
                ],
            );
            // A session can outlive its facilitator (row kept for history) — the
            // first joiner of an orphaned room takes the chair.
            if ($session->started_by_user_id === null) {
                $session->update(['started_by_user_id' => Auth::id()]);
            }
            // Add the user to the current round without clobbering an existing vote.
            // Re-joining switches role only for someone who hasn't voted yet.
            $vote = PlanningVote::firstOrCreate(
                ['planning_session_id' => $session->id, 'user_id' => Auth::id(), 'round' => $session->round],
                ['is_spectator' => (bool) ($validated['spectator'] ?? false)],
            );
            $vote->update([
                'last_seen_at' => now(),
                ...($vote->value === null
                    ? ['is_spectator' => (bool) ($validated['spectator'] ?? $vote->is_spectator)]
                    : []),
            ]);

            return $session;
        });

        return $this->broadcastSnapshot($session);
    }

    // Presence heartbeat — refreshes the caller's seat and sweeps out silent
    // non-voters. Only broadcasts when the sweep actually changed the roster.
    public function ping(int $boardId, int $cardId)
    {
        $this->authorizeScrumBoard($boardId);

        $session = PlanningSession::where('card_id', $cardId)->first();
        if (! $session) {
            return response()->noContent();
        }

        $session->votes()
            ->where('round', $session->round)
            ->where('user_id', Auth::id())
            ->update(['last_seen_at' => now()]);

        $this->settleTimer($session);
        if ($this->pruneStale($session, broadcastOnChange: true)) {
            return response()->noContent();
        }

        return response()->json($this->snapshot($session, Auth::id()));
    }

    public function vote(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeScrumBoard($boardId);
        $value = $request->input('value');

        // Settle a lapsed timer BEFORE the locked transaction — settling broadcasts,
        // and a broadcast must never describe state a later abort would roll back.
        $this->settleTimer($this->activeSession($cardId));

        $session = DB::transaction(function () use ($cardId, $value) {
            $session = $this->activeSession($cardId, lock: true);
            if (! in_array($value, self::DECKS[$session->deck] ?? self::DECKS['fib'], true)) {
                abort(422, 'That card is not in this session\'s deck.');
            }
            if ($session->revealed) {
                abort(422, 'Voting is closed for this round.');
            }
            $mine = $session->votes()
                ->where('round', $session->round)
                ->where('user_id', Auth::id())
                ->first();
            if ($mine?->is_spectator) {
                abort(422, 'Spectators don\'t vote — rejoin as a player first.');
            }
            PlanningVote::updateOrCreate(
                ['planning_session_id' => $session->id, 'user_id' => Auth::id(), 'round' => $session->round],
                ['value' => $value, 'voted_at' => now(), 'last_seen_at' => now()],
            );

            return $session;
        });

        return $this->broadcastSnapshot($session);
    }

    public function reveal(int $boardId, int $cardId)
    {
        $board = $this->authorizeScrumBoard($boardId);

        $session = DB::transaction(function () use ($board, $cardId) {
            $session = $this->activeSession($cardId, lock: true);
            $this->authorizeModerator($board, $session);
            $session->update(['revealed' => true, 'timer_ends_at' => null]);

            return $session;
        });

        return $this->broadcastSnapshot($session);
    }

    // Set (or clear, seconds=0) a soft voting deadline. When it lapses the round
    // auto-reveals on the next heartbeat that touches the session.
    public function timer(Request $request, int $boardId, int $cardId)
    {
        $board = $this->authorizeScrumBoard($boardId);
        $validated = $request->validate([
            'seconds' => ['required', 'integer', Rule::in([0, 30, 60, 120, 300])],
        ]);

        $session = DB::transaction(function () use ($board, $cardId, $validated) {
            $session = $this->activeSession($cardId, lock: true);
            $this->authorizeModerator($board, $session);
            if ($session->revealed) {
                abort(422, 'The round is already revealed.');
            }
            $session->update([
                'timer_ends_at' => $validated['seconds'] > 0
                    ? now()->addSeconds($validated['seconds'])
                    : null,
            ]);

            return $session;
        });

        return $this->broadcastSnapshot($session);
    }

    public function reset(int $boardId, int $cardId)
    {
        $board = $this->authorizeScrumBoard($boardId);

        $session = DB::transaction(function () use ($board, $cardId) {
            $session = $this->activeSession($cardId, lock: true);
            $this->authorizeModerator($board, $session);

            // Carry the current participants (and their roles) into a fresh round.
            $carried = $session->votes()
                ->where('round', $session->round)
                ->get(['user_id', 'is_spectator']);
            $session->update(['round' => $session->round + 1, 'revealed' => false, 'timer_ends_at' => null]);
            foreach ($carried as $prev) {
                PlanningVote::firstOrCreate(
                    ['planning_session_id' => $session->id, 'user_id' => $prev->user_id, 'round' => $session->round],
                    ['last_seen_at' => now(), 'is_spectator' => $prev->is_spectator],
                );
            }

            return $session;
        });

        return $this->broadcastSnapshot($session);
    }

    public function leave(int $boardId, int $cardId)
    {
        $this->authorizeScrumBoard($boardId);
        $session = PlanningSession::where('card_id', $cardId)->first();
        if (! $session) {
            return response()->noContent();
        }

        $closed = DB::transaction(function () use ($session) {
            $session->votes()->where('round', $session->round)->where('user_id', Auth::id())->delete();

            // Last one out closes the room.
            if ($session->votes()->where('round', $session->round)->count() === 0) {
                $session->delete();

                return true;
            }

            // The facilitator chair passes to the longest-seated remaining player
            // (spectators only inherit it when no players are left).
            if ($session->started_by_user_id === Auth::id()) {
                $next = $session->votes()
                    ->where('round', $session->round)
                    ->orderBy('is_spectator')
                    ->orderBy('id')
                    ->first();
                $session->update(['started_by_user_id' => $next?->user_id]);
            }

            return false;
        });

        if ($closed) {
            broadcast(new BoardEvent($session->board_id, 'planning.updated', ['card_id' => $cardId, 'board_id' => $session->board_id, 'cleared' => true]));

            return response()->noContent();
        }

        return $this->broadcastSnapshot($session);
    }

    public function apply(Request $request, int $boardId, int $cardId)
    {
        // Committing the estimate to the card is a write action. Only whole-number
        // deck cards can land on story_points (unsigned integer column) — ½, ?, ☕
        // and T-shirt sizes are for discussion, not for the field.
        $this->authorizeWrite($boardId);
        $deck = PlanningSession::where('card_id', $cardId)->value('deck') ?? 'fib';
        $applyable = array_map('intval', array_filter(
            self::DECKS[$deck] ?? self::DECKS['fib'],
            fn (string $v) => ctype_digit($v),
        ));
        if ($applyable === []) {
            abort(422, 'This deck does not map to story points.');
        }
        $validated = $request->validate([
            'value' => ['required', 'integer', Rule::in($applyable)],
        ]);

        $card = $this->boardCard($boardId, $cardId);
        $card->update(['story_points' => $validated['value']]);
        broadcast(new BoardEvent($boardId, 'card.updated', $card->fresh()->toArray()));

        // Stamp the apply moment so open modals can mirror the points field on the
        // apply *event* rather than on every snapshot; return the fresh snapshot.
        $session = PlanningSession::where('card_id', $cardId)->first();
        if ($session) {
            $session->update(['applied_at' => now()]);

            return $this->broadcastSnapshot($session);
        }

        return response()->json($card->fresh());
    }

    // --- helpers ---

    private function authorizeScrumBoard(int $boardId): Board
    {
        $board = $this->authorizeBoard($boardId);
        if ($board->type !== 'scrum') {
            abort(422, 'Planning Poker is only available on Scrum boards.');
        }

        return $board;
    }

    // Reveal/reset steer the whole table — reserved for the facilitator (session
    // starter, chair transfers on leave) or anyone with board write access.
    private function authorizeModerator(Board $board, PlanningSession $session): void
    {
        if (Auth::id() !== $session->started_by_user_id && ! $board->isWritableBy(Auth::id())) {
            abort(403, 'Only the session facilitator can do that.');
        }
    }

    // A lapsed voting timer settles the round: auto-reveal if anything was cast
    // (broadcast so every table flips), otherwise just clear the deadline.
    private function settleTimer(PlanningSession $session): void
    {
        if ($session->revealed || ! $session->timer_ends_at || $session->timer_ends_at->isFuture()) {
            return;
        }

        $hasVotes = $session->votes()
            ->where('round', $session->round)
            ->whereNotNull('value')
            ->exists();
        $session->update(['revealed' => $hasVotes, 'timer_ends_at' => null]);
        if ($hasVotes) {
            broadcast(new BoardEvent($session->board_id, 'planning.updated', $this->snapshot($session)));
        }
    }

    private function activeSession(int $cardId, bool $lock = false): PlanningSession
    {
        $query = PlanningSession::where('card_id', $cardId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /**
     * Presence sweep: while a round is open, joiners who never voted and have gone
     * silent past the TTL are removed. Returns true when the sweep emptied the
     * round and the session was closed (a `cleared` event is always broadcast then).
     */
    private function pruneStale(PlanningSession $session, bool $broadcastOnChange = false): bool
    {
        if ($session->revealed) {
            return false;
        }

        $cutoff = now()->subSeconds(self::PRESENCE_TTL_SECONDS);
        $stale = $session->votes()
            ->where('round', $session->round)
            ->whereNull('value')
            ->get()
            // Pre-presence rows have no last_seen_at — fall back to updated_at.
            ->filter(fn (PlanningVote $v) => ($v->last_seen_at ?? $v->updated_at) < $cutoff);

        if ($stale->isEmpty()) {
            return false;
        }

        PlanningVote::whereIn('id', $stale->pluck('id'))->delete();

        if ($session->votes()->where('round', $session->round)->count() === 0) {
            [$bid, $cid] = [$session->board_id, $session->card_id];
            $session->delete();
            broadcast(new BoardEvent($bid, 'planning.updated', ['card_id' => $cid, 'board_id' => $bid, 'cleared' => true]));

            return true;
        }

        if ($broadcastOnChange) {
            broadcast(new BoardEvent($session->board_id, 'planning.updated', $this->snapshot($session)));
        }

        return false;
    }

    private function broadcastSnapshot(PlanningSession $session)
    {
        $session->refresh();
        // Broadcast the anonymous snapshot; the HTTP response additionally carries
        // the caller's own vote (my_value) so their hand survives a reload.
        broadcast(new BoardEvent($session->board_id, 'planning.updated', $this->snapshot($session)));

        return response()->json($this->snapshot($session, Auth::id()));
    }

    // The anonymity gate: values are only included once the round is revealed.
    // With $forUserId set (HTTP responses, never broadcasts) the caller's own
    // pre-reveal value is included as `my_value`.
    private function snapshot(PlanningSession $session, ?int $forUserId = null): array
    {
        $card = Card::find($session->card_id);
        $votes = $session->votes()
            ->where('round', $session->round)
            ->with('user:id,name')
            ->orderBy('id')
            ->get();

        // Closed rounds are public record — that's what re-votes converge against.
        $history = $session->votes()
            ->where('round', '<', $session->round)
            ->where('round', '>=', $session->round - 5)
            ->whereNotNull('value')
            ->with('user:id,name')
            ->orderBy('round')
            ->orderBy('id')
            ->get()
            ->groupBy('round')
            ->map(fn ($roundVotes, $round) => [
                'round' => (int) $round,
                'votes' => $roundVotes->map(fn (PlanningVote $v) => [
                    'user_id' => $v->user_id,
                    'name' => $v->user?->name ?? 'Unknown',
                    'value' => $v->value,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        $data = [
            'card_id' => $session->card_id,
            'board_id' => $session->board_id,
            'round' => $session->round,
            'revealed' => (bool) $session->revealed,
            'started_by' => $session->started_by_user_id,
            'facilitator_id' => $session->started_by_user_id,
            'deck' => $session->deck,
            'hand' => self::DECKS[$session->deck] ?? self::DECKS['fib'],
            'timer_ends_at' => $session->timer_ends_at?->toISOString(),
            'participants' => $votes->map(fn (PlanningVote $v) => [
                'user_id' => $v->user_id,
                'name' => $v->user?->name ?? 'Unknown',
                'has_voted' => $v->value !== null,
                'value' => $session->revealed ? $v->value : null,
                'is_spectator' => (bool) $v->is_spectator,
            ])->values()->all(),
            'applied_value' => $card?->story_points,
            'applied_at' => $session->applied_at?->toISOString(),
            'history' => $history,
        ];

        if ($forUserId !== null) {
            $data['my_value'] = $votes->firstWhere('user_id', $forUserId)?->value;
        }

        return $data;
    }
}
