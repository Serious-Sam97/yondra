<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Project;
use App\Services\BoardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        return $this->boardService->fetchAll();
    }

    public function show(int $boardId)
    {
        return $this->boardService->fetchOne($boardId);
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
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'project_id'  => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $this->authorizeProject($validated['project_id'] ?? null);

        return response()->json($this->boardService->create($validated), 201);
    }

    public function update(Request $request, int $boardId)
    {
        $validated = $request->validate([
            'name'          => ['sometimes', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'project_id'    => ['nullable', 'integer', 'exists:projects,id'],
            'ticket_prefix' => ['sometimes', 'nullable', 'string', 'max:10'],
        ]);

        $this->authorizeProject($validated['project_id'] ?? null);

        if (array_key_exists('ticket_prefix', $validated)) {
            // Normalize to an uppercase, whitespace-free code; blank means "no prefix".
            $prefix = strtoupper(preg_replace('/\s+/', '', (string) $validated['ticket_prefix']));
            $validated['ticket_prefix'] = $prefix === '' ? null : $prefix;
        }

        $validated['id'] = $boardId;

        return $this->boardService->edit($validated);
    }

    private function authorizeProject(?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        $project = Project::findOrFail($projectId);
        if (!$project->isAccessibleBy(Auth::id())) {
            throw new AccessDeniedHttpException();
        }
    }
}
