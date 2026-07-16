<?php

namespace App\Http\Controllers;

use App\Events\ProjectEvent;
use App\Http\Resources\BoardResource;
use App\Http\Resources\BoardSummaryResource;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Project;
use App\Services\BoardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BoardController extends Controller
{
    public BoardService $boardService;

    public function __construct()
    {
        $this->boardService = resolve(BoardService::class);
    }

    public function index()
    {
        $boards = $this->boardService->fetchAll();
        $summaries = fn ($group) => $group->map(
            fn ($board) => new BoardSummaryResource($board, withSharePermissions: true)
        );

        return [
            'owned' => $summaries($boards['owned']),
            'shared' => $summaries($boards['shared']),
        ];
    }

    public function show(Request $request, int $boardId)
    {
        return new BoardResource(
            $this->boardService->fetchOne($boardId, $request->boolean('include_subtasks'))
        );
    }

    public function destroy(int $boardId)
    {
        $this->authorizeManage($boardId);
        $this->boardService->remove($boardId);

        return response()->json(null, 204);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'type' => ['sometimes', 'in:kanban,scrum,crm'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $this->authorizeProject($validated['project_id'] ?? null);

        $board = $this->boardService->create($validated);

        // Live-add the board to the project's board list for everyone viewing it.
        if ($board->project_id) {
            broadcast(new ProjectEvent(
                $board->project_id,
                'board.created',
                $board->fresh()->load('owner:id,name,email')->toArray(),
            ));
        }

        return (new BoardSummaryResource($board))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $boardId)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'type' => ['sometimes', 'in:kanban,scrum,crm'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'done_section_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
            // CRM loss config (YON-66): the designated Lost stage + the editable
            // list of loss reasons required when a deal enters it.
            'lost_section_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
            'loss_reasons' => ['sometimes', 'nullable', 'array'],
            'loss_reasons.*' => ['string', 'max:120'],
            'qa_enabled' => ['sometimes', 'boolean'],
            'ticket_prefix' => ['sometimes', 'nullable', 'string', 'max:10'],
            'next_ticket_number' => ['sometimes', 'integer', 'min:1'],
            'background' => ['sometimes', 'nullable', 'string', 'max:40'],
            'default_permission' => ['sometimes', 'in:read,write,owner'],
            'github_repo' => ['sometimes', 'nullable', 'string', 'regex:/^[\w.-]+\/[\w.-]+$/'],
            'github_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'whatsapp_provider' => ['sometimes', 'nullable', 'in:meta,bsp'],
            'whatsapp_phone_number_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'whatsapp_waba_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'whatsapp_token' => ['sometimes', 'nullable', 'string', 'max:512'],
            'whatsapp_app_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'whatsapp_verify_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Toggle the JotForm intake webhook. Enabling mints a token; disabling clears it.
            'intake_enabled' => ['sometimes', 'boolean'],
            // Field mapping rules (YON-50): [{source, target}, …].
            'intake_field_map' => ['sometimes', 'nullable', 'array'],
            'intake_field_map.*.source' => ['required_with:intake_field_map', 'string', 'max:120'],
            'intake_field_map.*.target' => ['required_with:intake_field_map', 'string', 'in:title,description,value,tags,priority,story_points,due_date,contact_name,contact_email,contact_phone,ignore'],
            // Email deliverability (YON-51/52).
            'email_spam_safe' => ['sometimes', 'boolean'],
            'require_optin_before_email' => ['sometimes', 'boolean'],
            // Invoice issuer / emitente details stamped on generated nota fiscais (YON-68).
            'invoice_issuer' => ['sometimes', 'nullable', 'array'],
            'invoice_issuer.name' => ['nullable', 'string', 'max:160'],
            'invoice_issuer.tax_id' => ['nullable', 'string', 'max:40'],
            'invoice_issuer.address' => ['nullable', 'string', 'max:255'],
            'invoice_issuer.email' => ['nullable', 'string', 'max:160'],
            'invoice_issuer.phone' => ['nullable', 'string', 'max:40'],
            'invoice_issuer.footer' => ['nullable', 'string', 'max:255'],
            // Roadmap flowchart (YON-120): positioned step nodes (one per section)
            // + directed edges. Null clears back to auto-layout.
            'roadmap_config' => ['sometimes', 'nullable', 'array'],
            'roadmap_config.nodes' => ['required_with:roadmap_config', 'array'],
            'roadmap_config.nodes.*.section_id' => ['required', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
            'roadmap_config.nodes.*.x' => ['required', 'numeric'],
            'roadmap_config.nodes.*.y' => ['required', 'numeric'],
            'roadmap_config.edges' => ['sometimes', 'array'],
            'roadmap_config.edges.*.from' => ['required', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
            'roadmap_config.edges.*.to' => ['required', 'integer', Rule::exists('sections', 'id')->where('board_id', $boardId)],
        ]);

        $this->authorizeProject($validated['project_id'] ?? null);

        if (array_key_exists('ticket_prefix', $validated)) {
            // Normalize to an uppercase, whitespace-free code; blank means "no prefix".
            $prefix = strtoupper(preg_replace('/\s+/', '', (string) $validated['ticket_prefix']));
            $validated['ticket_prefix'] = $prefix === '' ? null : $prefix;
        }

        $validated['id'] = $boardId;

        // Capture the source project before the edit so a cross-project move can
        // notify both the old and new project channels (YON-125).
        $oldProjectId = Board::whereKey($boardId)->value('project_id');

        $board = $this->boardService->edit($validated);

        if (array_key_exists('project_id', $validated) && $board->project_id !== $oldProjectId) {
            if ($oldProjectId) {
                broadcast(new ProjectEvent($oldProjectId, 'board.removed', ['board_id' => $boardId]));
            }
            // Reuse the 'board.created' frame so a viewer of the destination
            // project appends the board through the existing live-add handler.
            if ($board->project_id) {
                broadcast(new ProjectEvent(
                    $board->project_id,
                    'board.created',
                    $board->fresh()->load('owner:id,name,email')->toArray(),
                ));
            }
        }

        return new BoardSummaryResource($board, withConnectionFlags: true);
    }

    public function archive(int $boardId)
    {
        $this->authorizeManage($boardId);

        return $this->boardService->setArchived($boardId, true);
    }

    public function unarchive(int $boardId)
    {
        $this->authorizeManage($boardId);

        return $this->boardService->setArchived($boardId, false);
    }

    public function copy(Request $request, int $boardId)
    {
        // Any member who can see the board may clone it into their own space.
        $this->authorizeBoard($boardId);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'include_cards' => ['sometimes', 'boolean'],
        ]);

        $copy = $this->boardService->duplicate($boardId, $validated['name'] ?? null, (bool) ($validated['include_cards'] ?? false));

        return (new BoardSummaryResource($copy))->response()->setStatusCode(201);
    }

    private function authorizeProject(?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        $project = Project::findOrFail($projectId);
        if (! $project->isAccessibleBy(Auth::id())) {
            throw new AccessDeniedHttpException;
        }
    }
}
