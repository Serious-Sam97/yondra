<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Services\SectionService;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public SectionService $sectionService;

    public function __construct()
    {
        $this->sectionService = resolve(SectionService::class);
    }

    public function destroy(int $boardId, int $sectionId)
    {
        $this->authorizeWrite($boardId);
        $this->sectionService->remove($boardId, $sectionId);
        broadcast(new BoardEvent($boardId, 'section.deleted', ['id' => $sectionId]));
        return response()->json(null, 204);
    }

    public function update(Request $request, int $boardId, int $sectionId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'aging_hours' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $section = $this->sectionService->edit($boardId, $sectionId, $validated);
        broadcast(new BoardEvent($boardId, 'section.updated', $section->toArray()));
        return response()->json($section);
    }

    public function reorder(Request $request, int $boardId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'section_ids'   => ['required', 'array'],
            'section_ids.*' => ['integer'],
        ]);

        $this->sectionService->reorder($boardId, $validated['section_ids']);
        broadcast(new BoardEvent($boardId, 'sections.reordered', ['section_ids' => $validated['section_ids']]));
        return response()->json(null, 204);
    }

    public function store(Request $request, int $boardId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $section = $this->sectionService->create([
            'board_id' => $boardId,
            'name'     => $validated['name'],
        ]);

        broadcast(new BoardEvent($boardId, 'section.created', $section->toArray()));
        return response()->json($section, 201);
    }
}
