<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCardSummaryJob;
use App\Services\Ai\AiDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiAssistController extends Controller
{
    /**
     * Kick off a streamed card-thread summary. Read-only, so board *access* is enough.
     * The heavy work (an outbound streaming LLM call) runs off the request thread in a
     * queued job that broadcasts `ai.token`/`ai.done` frames on the board channel.
     *
     * The availability gate goes through the injected AiDriver interface — no provider
     * knowledge here. The client mints the `request_id` and arms its listener BEFORE
     * calling, so no early token can be missed; we fall back to a server id otherwise.
     */
    public function summarize(Request $request, AiDriver $ai, int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $this->boardCard($boardId, $cardId);

        if (! $ai->isAvailable()) {
            abort(503, 'AI assist is not configured.');
        }

        $validated = $request->validate([
            'request_id' => ['sometimes', 'string', 'max:64'],
        ]);
        $requestId = $validated['request_id'] ?? (string) Str::uuid();

        GenerateCardSummaryJob::dispatch($boardId, $cardId, $requestId);

        return response()->json(['request_id' => $requestId], 202);
    }
}
