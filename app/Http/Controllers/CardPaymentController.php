<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\CardInvoice;
use App\Infrastructure\Models\CardPayment;
use App\Infrastructure\Models\PaymentMilestoneEvent;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Payment ledger for a CRM deal (YON-63): list / add / remove installments. Adding
 * a payment recomputes the deal's paid total and triggers milestone evaluation.
 */
class CardPaymentController extends Controller
{
    private PaymentService $payments;

    public function __construct()
    {
        $this->payments = resolve(PaymentService::class);
    }

    /** Summary + ledger + milestone-firing history for the card's Payments panel. */
    public function index(int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId)->load('board');

        return response()->json($this->payload($card));
    }

    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId)->load('board');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $this->payments->record($card, $validated, Auth::id());

        $payload = $this->payload($card->fresh()->load('board'));
        broadcast(new BoardEvent($boardId, 'card.payment', ['card_id' => $cardId, 'summary' => $payload['summary']]));

        return response()->json($payload, 201);
    }

    public function destroy(int $boardId, int $cardId, int $paymentId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId)->load('board');

        $payment = CardPayment::where('card_id', $cardId)->findOrFail($paymentId);
        $this->payments->remove($payment);

        $payload = $this->payload($card->fresh()->load('board'));
        broadcast(new BoardEvent($boardId, 'card.payment', ['card_id' => $cardId, 'summary' => $payload['summary']]));

        return response()->json($payload);
    }

    /** Shape the Payments panel payload: headline summary + ledger rows + fired milestones. */
    public function payload($card): array
    {
        $payments = CardPayment::where('card_id', $card->id)
            ->with('recordedBy:id,name')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CardPayment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'note' => $p->note,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'recorded_by' => $p->recordedBy?->name,
            ]);

        $events = PaymentMilestoneEvent::where('card_id', $card->id)
            ->with('milestone:id,label')
            ->orderBy('threshold_pct')
            ->get()
            ->map(fn (PaymentMilestoneEvent $e) => [
                'threshold_pct' => $e->threshold_pct,
                'label' => $e->milestone?->label,
                'message_status' => $e->message_status,
                'message_channel' => $e->message_channel,
                'moved_to_section_id' => $e->moved_to_section_id,
                'invoice_status' => $e->invoice_status,
                'invoice_number' => $e->invoice_number,
                'error' => $e->error,
                'triggered_at' => $e->triggered_at?->toIso8601String(),
            ]);

        return [
            'summary' => $this->payments->summary($card),
            'payments' => $payments,
            'events' => $events,
            'invoice' => $this->invoicePayload($card),
        ];
    }

    /** The issued nota fiscal for this card, if any (YON-68), with its download handle. */
    private function invoicePayload($card): ?array
    {
        $invoice = CardInvoice::where('card_id', $card->id)->first();
        if (! $invoice) {
            return null;
        }

        return [
            'number' => $invoice->number,
            'amount' => (float) $invoice->amount,
            'currency' => $invoice->currency,
            'document_id' => $invoice->document_id,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
        ];
    }
}
