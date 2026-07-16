<?php

namespace App\Http\Controllers;

use App\Events\ProjectEvent;
use App\Services\ProjectService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    private ProjectService $service;

    public function __construct()
    {
        $this->service = resolve(ProjectService::class);
    }

    public function index()
    {
        return $this->service->fetchAll();
    }

    public function show(int $projectId)
    {
        return $this->service->fetchOne($projectId);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        return response()->json($this->service->create($validated), 201);
    }

    public function update(Request $request, int $projectId)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'default_permission' => ['sometimes', 'in:read,write,owner'],
        ]);

        $validated['id'] = $projectId;

        return $this->service->update($validated);
    }

    public function destroy(int $projectId)
    {
        $this->service->remove($projectId);

        return response()->json(null, 204);
    }

    public function archive(int $projectId)
    {
        return $this->service->setArchived($projectId, true);
    }

    public function unarchive(int $projectId)
    {
        return $this->service->setArchived($projectId, false);
    }

    // Persist a drag-reordered board list within a project (YON-125). Project
    // owners only; ids from other projects are ignored by the repository.
    public function reorderBoards(Request $request, int $projectId)
    {
        $validated = $request->validate([
            'board_ids' => ['required', 'array'],
            'board_ids.*' => ['integer'],
        ]);

        $this->service->reorderBoards($projectId, $validated['board_ids']);
        broadcast(new ProjectEvent($projectId, 'boards.reordered', ['board_ids' => $validated['board_ids']]));

        return response()->json(null, 204);
    }

    public function copy(Request $request, int $projectId)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'include_boards' => ['sometimes', 'boolean'],
            'include_cards' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->service->duplicate(
            $projectId,
            $validated['name'] ?? null,
            (bool) ($validated['include_boards'] ?? true),
            (bool) ($validated['include_cards'] ?? false),
        ), 201);
    }
}
