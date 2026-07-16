<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Auth;

/**
 * Manual issuing of a nota fiscal / invoice for a CRM deal (YON-68). The same
 * document is also produced automatically by the "generate invoice" payment
 * milestone; this endpoint lets an operator (re)issue it on demand.
 */
class CardInvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /** Issue (or re-issue) the card's invoice, then return the refreshed Payments payload. */
    public function store(int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId)->load('board');

        $this->invoices->issueForCard($card, Auth::id());

        // Refresh the board's in-memory card so the attached PDF survives a reopen.
        $this->broadcastCard($boardId, $cardId);

        $payload = resolve(CardPaymentController::class)->payload($card->fresh()->load('board'));
        broadcast(new BoardEvent($boardId, 'card.payment', ['card_id' => $cardId, 'summary' => $payload['summary']]));

        return response()->json($payload, 201);
    }

    /** Reload the card with its client-facing relations and broadcast it to the board. */
    private function broadcastCard(int $boardId, int $cardId): void
    {
        $card = Card::with(['assignedUser:id,name', 'createdBy:id,name', 'tags', 'images', 'links', 'documents'])->findOrFail($cardId);
        $board = Board::find($boardId);
        $card->ticket_key = Card::ticketKey($board?->ticket_prefix, $card->ticket_number);

        broadcast(new BoardEvent($boardId, 'card.updated', $card->toArray()));
    }
}
