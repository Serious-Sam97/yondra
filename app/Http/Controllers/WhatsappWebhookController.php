<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use App\Services\WhatsappService;
use Illuminate\Http\Request;

/**
 * Inbound WhatsApp Cloud API webhooks — public, authenticated per-board via the
 * verify token (GET handshake) and the HMAC signature (POST). Mirrors the GitHub
 * webhook pattern; lives outside the auth:sanctum group.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(private WhatsappService $whatsapp) {}

    /** Meta's subscription handshake: echo hub.challenge when the verify token matches. */
    public function verify(Request $request, int $boardId)
    {
        $board = Board::find($boardId);
        $expected = $board?->whatsapp_verify_token ?: config('services.whatsapp.meta.verify_token');

        if ($request->query('hub_mode') === 'subscribe'
            && $expected
            && hash_equals((string) $expected, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request, int $boardId)
    {
        $board = Board::find($boardId);
        if (! $board) {
            return response()->json(['message' => 'Unknown board.'], 404);
        }

        if (! $this->whatsapp->driverFor($board)->verifySignature(
            $board,
            $request->getContent(),
            $request->header('X-Hub-Signature-256'),
        )) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $stored = $this->whatsapp->handleInbound($board, $request->json()->all());

        // Always 200 so Meta doesn't retry a payload we've already accepted.
        return response()->json(['ok' => true, 'stored' => $stored]);
    }
}
