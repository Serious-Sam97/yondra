<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardLink;
use App\Infrastructure\Repository\CardModelRepository;
use App\Services\GitHubService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CardLinkController extends Controller
{
    public function __construct(private GitHubService $github)
    {
    }

    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500'],
        ]);

        $parts = $this->github->parse($validated['url']);
        if (!$parts) {
            return response()->json(['message' => 'That is not a GitHub pull request or issue URL.'], 422);
        }

        $link = CardLink::firstOrNew([
            'card_id' => $card->id,
            'url'     => $validated['url'],
        ]);
        $link->fill([
            'board_id'           => $boardId,
            'created_by_user_id' => Auth::id(),
            'provider'           => 'github',
            'type'               => $parts['type'],
            'owner'              => $parts['owner'],
            'repo'               => $parts['repo'],
            'number'             => $parts['number'],
            'html_url'           => $validated['url'],
        ]);
        $link->save();

        // Pull live state immediately (no-op without a connected token).
        $this->github->sync($link);

        return response()->json($this->broadcastCard($boardId, $card->id), 201);
    }

    public function refresh(int $boardId, int $cardId, int $linkId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);

        $link = CardLink::where('card_id', $card->id)->findOrFail($linkId);
        $this->github->sync($link);

        return response()->json($this->broadcastCard($boardId, $card->id));
    }

    public function destroy(int $boardId, int $cardId, int $linkId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);

        CardLink::where('card_id', $card->id)->findOrFail($linkId)->delete();

        $this->broadcastCard($boardId, $card->id);
        return response()->json(null, 204);
    }

    /** Reload the card with its client-facing relations, broadcast it, and return it. */
    private function broadcastCard(int $boardId, int $cardId): array
    {
        $card = Card::with(['assignedUser:id,name', 'createdBy:id,name', 'tags', 'images', 'links'])->findOrFail($cardId);
        $board = Board::find($boardId);
        $card->ticket_key = CardModelRepository::composeTicketKey($board?->ticket_prefix, $card->ticket_number);
        $payload = $card->toArray();

        broadcast(new BoardEvent($boardId, 'card.updated', $payload));
        return $payload;
    }
}
