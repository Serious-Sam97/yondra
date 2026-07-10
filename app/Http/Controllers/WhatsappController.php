<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\WhatsappConversation;
use App\Services\WhatsappService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Per-card WhatsApp thread: read the conversation + messages, and reply to the
 * customer from inside the card (card #55). Authorized like every other card sub-resource.
 */
class WhatsappController extends Controller
{
    public function __construct(private WhatsappService $whatsapp) {}

    /** The card's conversation (if any) with its messages, oldest first. */
    public function show(int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId);

        $conversation = WhatsappConversation::where('card_id', $card->id)
            ->with(['messages.sentBy:id,name'])
            ->first();

        return response()->json([
            'conversation' => $conversation,
            'window_open' => (bool) $conversation?->windowOpen(),
        ]);
    }

    /** Send a free-form reply (inside the 24h window) or an approved template. */
    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);

        $validated = $request->validate([
            'body' => ['required_without:template', 'nullable', 'string'],
            'template' => ['required_without:body', 'nullable', 'string'],
            'language' => ['nullable', 'string'],
            'components' => ['sometimes', 'array'],
        ]);

        try {
            if (! empty($validated['template'])) {
                $message = $this->whatsapp->sendTemplateToCard(
                    $card,
                    $validated['template'],
                    $validated['language'] ?? 'en',
                    $validated['components'] ?? [],
                    (int) Auth::id(),
                );
            } else {
                $message = $this->whatsapp->replyToCard($card, $validated['body'], (int) Auth::id());
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $status = $message->status === 'failed' ? 502 : 201;

        return response()->json($message->load('sentBy:id,name'), $status);
    }
}
