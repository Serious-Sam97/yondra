<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Project;
use App\Jobs\GenerateAiAssistJob;
use App\Jobs\GenerateBoardSummaryJob;
use App\Jobs\GenerateCrmChatJob;
use App\Jobs\GenerateWorkspaceChatJob;
use App\Services\Ai\AiDriver;
use App\Services\AiAssistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiAssistController extends Controller
{
    /** Card AI actions. The route constrains {action} to these keys. */
    public const ACTIONS = ['summarize', 'describe', 'checklist', 'tests', 'reply', 'rewrite'];

    /**
     * Kick off a streamed AI action on a card. Read-only (generation only — applying the
     * result goes through the normal card/WhatsApp writes), so board *access* is enough.
     * The heavy work runs off the request thread in a queued job that broadcasts
     * `ai.token`/`ai.done` frames on the board channel.
     *
     * The availability gate goes through the injected AiDriver interface — no provider
     * knowledge here. The client mints the `request_id` and arms its listener BEFORE
     * calling, so no early token can be missed; we fall back to a server id otherwise.
     */
    public function run(Request $request, AiDriver $ai, int $boardId, int $cardId, string $action)
    {
        $this->authorizeBoard($boardId);
        $this->boardCard($boardId, $cardId);

        // Belt-and-braces — the route already constrains {action}, but never trust it blind.
        abort_unless(in_array($action, self::ACTIONS, true), 404);

        if (! $ai->isAvailable()) {
            abort(503, 'AI assist is not configured.');
        }

        $validated = $request->validate([
            'request_id' => ['sometimes', 'string', 'max:64'],
            'prompt' => ['sometimes', 'string', 'max:2000'],                    // describe/reply steer
            'mode' => ['sometimes', 'string', 'in:improve,grammar,concise,translate'], // rewrite
            'language' => ['sometimes', 'string', 'max:40'],                    // rewrite: translate target
            'text' => ['sometimes', 'string', 'max:20000'],                    // unsaved editor content
        ]);

        $requestId = $validated['request_id'] ?? (string) Str::uuid();
        $options = array_intersect_key($validated, array_flip(['prompt', 'mode', 'language', 'text']));

        GenerateAiAssistJob::dispatch($boardId, $cardId, $requestId, $action, $options);

        return response()->json(['request_id' => $requestId], 202);
    }

    /**
     * Suggest a story-point estimate — a short, structured (JSON) answer, so it runs
     * SYNCHRONOUSLY and returns the value directly rather than streaming. Read-only.
     */
    public function suggestPoints(AiDriver $ai, AiAssistService $service, int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $this->boardCard($boardId, $cardId);

        if (! $ai->isAvailable()) {
            abort(503, 'AI assist is not configured.');
        }

        try {
            return response()->json($service->suggestPoints($boardId, $cardId));
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('AI points suggestion failed', ['card' => $cardId, 'error' => $e->getMessage()]);
            abort(502, 'Could not get a suggestion. Try again.');
        }
    }

    /**
     * Suggest triage (labels, priority, assignee) — a short structured JSON answer, run
     * SYNCHRONOUSLY. The model only picks from the board's real tags/members. Read-only.
     */
    public function suggestTriage(AiDriver $ai, AiAssistService $service, int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $this->boardCard($boardId, $cardId);

        if (! $ai->isAvailable()) {
            abort(503, 'AI assist is not configured.');
        }

        try {
            return response()->json($service->suggestTriage($boardId, $cardId));
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('AI triage failed', ['card' => $cardId, 'error' => $e->getMessage()]);
            abort(502, 'Could not get a suggestion. Try again.');
        }
    }

    /** Suggest a breakdown of the card into subtask titles. Synchronous JSON; the client creates the child cards. */
    public function suggestSubtasks(AiDriver $ai, AiAssistService $service, int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $this->boardCard($boardId, $cardId);

        if (! $ai->isAvailable()) {
            abort(503, 'AI assist is not configured.');
        }

        try {
            return response()->json($service->suggestSubtasks($boardId, $cardId));
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('AI subtask breakdown failed', ['card' => $cardId, 'error' => $e->getMessage()]);
            abort(502, 'Could not get a suggestion. Try again.');
        }
    }

    /**
     * Kick off a board-level standup / sprint summary. Board-scoped and streamed (frames
     * carry scope:'board'), so it runs off the request thread like the card actions.
     */
    public function standup(Request $request, AiDriver $ai, int $boardId)
    {
        $this->authorizeBoard($boardId);

        if (! $ai->isAvailable()) {
            abort(503, 'AI assist is not configured.');
        }

        $validated = $request->validate([
            'request_id' => ['sometimes', 'string', 'max:64'],
            'sprint_id' => ['sometimes', 'integer'],
        ]);
        $requestId = $validated['request_id'] ?? (string) Str::uuid();

        GenerateBoardSummaryJob::dispatch($boardId, $requestId, $validated['sprint_id'] ?? null);

        return response()->json(['request_id' => $requestId], 202);
    }

    /**
     * One turn of the board-level CRM assistant (YON-69) — a multi-turn chat grounded in
     * the board's current pipeline. Board-scoped and streamed (frames carry
     * scope:'crm-chat'), so it runs off the request thread like the standup. The full
     * conversation so far is sent each turn; the model never mutates anything.
     */
    public function crmChat(Request $request, AiDriver $ai, int $boardId)
    {
        $this->authorizeBoard($boardId);

        if (! $ai->isAvailable()) {
            abort(503, 'AI assist is not configured.');
        }

        $validated = $request->validate([
            'request_id' => ['sometimes', 'string', 'max:64'],
            'messages' => ['required', 'array', 'min:1', 'max:30'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:4000'],
        ]);
        $requestId = $validated['request_id'] ?? (string) Str::uuid();

        // Re-key to a clean list of {role, content} — drop any extra fields the client sent.
        $messages = array_map(
            fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
            $validated['messages'],
        );

        GenerateCrmChatJob::dispatch($boardId, $requestId, array_values($messages));

        return response()->json(['request_id' => $requestId], 202);
    }

    /**
     * One turn of Vortex, the user-scoped workspace assistant (mascot chat). No board in
     * the URL — by default the snapshot covers every board the caller can see, and the
     * reply streams back on their own private channel as scope:'vortex-chat' frames.
     * Same conversation contract as the CRM chat: the client posts the whole transcript
     * each turn.
     *
     * Optional `mounts` focus the grounding on specific contexts (deep board blocks /
     * project-wide blocks). Every mount is access-checked HERE, per request — a stale
     * chip for a board the caller lost access to gets a 422 telling them to eject it,
     * never a silent leak into the snapshot.
     */
    public function workspaceChat(Request $request, AiDriver $ai)
    {
        if (! $ai->isAvailable()) {
            abort(503, 'AI assist is not configured.');
        }

        $validated = $request->validate([
            'request_id' => ['sometimes', 'string', 'max:64'],
            'messages' => ['required', 'array', 'min:1', 'max:30'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:4000'],
            'mounts' => ['sometimes', 'array', 'max:6'],
            'mounts.*.type' => ['required', 'string', 'in:project,board'],
            'mounts.*.id' => ['required', 'integer', 'min:1'],
        ]);
        $requestId = $validated['request_id'] ?? (string) Str::uuid();
        $userId = (int) $request->user()->id;

        $mounts = [];
        foreach ($validated['mounts'] ?? [] as $m) {
            $id = (int) $m['id'];
            $accessible = $m['type'] === 'board'
                ? (($board = Board::find($id)) && $board->archived_at === null && $board->isAccessibleBy($userId))
                : (($project = Project::find($id)) && $project->archived_at === null && $project->isAccessibleBy($userId));
            abort_unless($accessible, 422, 'A mounted project or board is gone or no longer accessible — eject it and try again.');
            $mounts[] = ['type' => $m['type'], 'id' => $id];
        }

        $messages = array_map(
            fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
            $validated['messages'],
        );

        GenerateWorkspaceChatJob::dispatch($userId, $requestId, array_values($messages), $mounts);

        return response()->json(['request_id' => $requestId], 202);
    }
}
