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
        $this->sectionService->remove($sectionId);
        broadcast(new BoardEvent($boardId, 'section.deleted', ['id' => $sectionId]));
        return response()->json(null, 204);
    }

    public function update(Request $request, int $boardId, int $sectionId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $section = $this->sectionService->rename($sectionId, $validated['name']);
        broadcast(new BoardEvent($boardId, 'section.updated', (array) $section));
        return response()->json($section);
    }

    public function reorder(Request $request, int $boardId)
    {
        $this->authorizeWrite($boardId);
        $validated = $request->validate([
            'section_ids'   => ['required', 'array'],
            'section_ids.*' => ['integer'],
        ]);

        $this->sectionService->reorder($validated['section_ids']);
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

        broadcast(new BoardEvent($boardId, 'section.created', (array) $section));
        return response()->json($section, 201);
    }
}
