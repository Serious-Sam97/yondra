<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\PlanningSession;
use App\Infrastructure\Models\PlanningVote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PlanningController extends Controller
{
    // The Fibonacci hand a participant can play ('?' = "no idea").
    private const HAND = ['1', '2', '3', '5', '8', '13', '21', '?'];

    public function show(int $boardId, int $cardId)
    {
        $board = $this->authorizeBoard($boardId);
        $this->boardCard($boardId, $cardId);
        if ($board->type !== 'scrum') {
            return response()->noContent();
        }

        $session = PlanningSession::where('card_id', $cardId)->first();

        // 204 (not json(null)) — Symfony's JsonResponse encodes null as "{}".
        return $session ? response()->json($this->snapshot($session)) : response()->noContent();
    }

    public function join(int $boardId, int $cardId)
    {
        $board = $this->authorizeScrumBoard($boardId);
        $this->boardCard($boardId, $cardId);

        $session = PlanningSession::firstOrCreate(
            ['card_id' => $cardId],
            ['board_id' => $boardId, 'round' => 1, 'revealed' => false, 'started_by_user_id' => Auth::id()],
        );
        // Add the user to the current round without clobbering an existing vote.
        PlanningVote::firstOrCreate(
            ['planning_session_id' => $session->id, 'user_id' => Auth::id(), 'round' => $session->round],
        );

        return $this->broadcastSnapshot($session);
    }

    public function vote(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeScrumBoard($boardId);
        $validated = $request->validate(['value' => ['required', Rule::in(self::HAND)]]);

        $session = $this->activeSession($cardId);
        if ($session->revealed) {
            return response()->json(['message' => 'Voting is closed for this round.'], 422);
        }
        PlanningVote::updateOrCreate(
            ['planning_session_id' => $session->id, 'user_id' => Auth::id(), 'round' => $session->round],
            ['value' => $validated['value'], 'voted_at' => now()],
        );

        return $this->broadcastSnapshot($session);
    }

    public function reveal(int $boardId, int $cardId)
    {
        $this->authorizeScrumBoard($boardId);
        $session = $this->activeSession($cardId);
        $session->update(['revealed' => true]);

        return $this->broadcastSnapshot($session);
    }

    public function reset(int $boardId, int $cardId)
    {
        $this->authorizeScrumBoard($boardId);
        $session = $this->activeSession($cardId);

        // Carry the current participants into a fresh round with cleared votes.
        $participantIds = $session->votes()->where('round', $session->round)->pluck('user_id')->all();
        $session->update(['round' => $session->round + 1, 'revealed' => false]);
        foreach ($participantIds as $uid) {
            PlanningVote::firstOrCreate(
                ['planning_session_id' => $session->id, 'user_id' => $uid, 'round' => $session->round],
            );
        }

        return $this->broadcastSnapshot($session);
    }

    public function leave(int $boardId, int $cardId)
    {
        $this->authorizeScrumBoard($boardId);
        $session = PlanningSession::where('card_id', $cardId)->first();
        if (! $session) {
            return response()->noContent();
        }

        $session->votes()->where('round', $session->round)->where('user_id', Auth::id())->delete();

        // Last one out closes the room.
        if ($session->votes()->where('round', $session->round)->count() === 0) {
            $session->delete();
            broadcast(new BoardEvent($boardId, 'planning.updated', ['card_id' => $cardId, 'board_id' => $boardId, 'cleared' => true]));

            return response()->noContent();
        }

        return $this->broadcastSnapshot($session);
    }

    public function apply(Request $request, int $boardId, int $cardId)
    {
        // Committing the estimate to the card is a write action.
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'value' => ['required', 'integer', Rule::in([1, 2, 3, 5, 8, 13, 21])],
        ]);

        $card = $this->boardCard($boardId, $cardId);
        $card->update(['story_points' => $validated['value']]);
        broadcast(new BoardEvent($boardId, 'card.updated', $card->fresh()->toArray()));

        // Return the planning snapshot (its applied_value now reflects the new points)
        // so the card's story-points field can sync; fall back to the card if no session.
        $session = PlanningSession::where('card_id', $cardId)->first();

        return $session ? $this->broadcastSnapshot($session) : response()->json($card->fresh());
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

    private function activeSession(int $cardId): PlanningSession
    {
        return PlanningSession::where('card_id', $cardId)->firstOrFail();
    }

    private function broadcastSnapshot(PlanningSession $session)
    {
        $snapshot = $this->snapshot($session);
        broadcast(new BoardEvent($session->board_id, 'planning.updated', $snapshot));

        return response()->json($snapshot);
    }

    // The anonymity gate: values are only included once the round is revealed.
    private function snapshot(PlanningSession $session): array
    {
        $card = Card::find($session->card_id);
        $votes = $session->votes()->where('round', $session->round)->with('user:id,name')->get();

        return [
            'card_id' => $session->card_id,
            'board_id' => $session->board_id,
            'round' => $session->round,
            'revealed' => (bool) $session->revealed,
            'started_by' => $session->started_by_user_id,
            'participants' => $votes->map(fn (PlanningVote $v) => [
                'user_id' => $v->user_id,
                'name' => $v->user?->name ?? 'Unknown',
                'has_voted' => $v->value !== null,
                'value' => $session->revealed ? $v->value : null,
            ])->values()->all(),
            'applied_value' => $card?->story_points,
        ];
    }
}
