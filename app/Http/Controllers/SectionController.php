<?php

namespace App\Http\Controllers;

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
        $this->authorizeBoard($boardId);
        $this->sectionService->remove($sectionId);
        return response()->json(null, 204);
    }

    public function update(Request $request, int $boardId, int $sectionId)
    {
        $this->authorizeBoard($boardId);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $section = $this->sectionService->rename($sectionId, $validated['name']);
        return response()->json($section);
    }

    public function store(Request $request, int $boardId)
    {
        $this->authorizeBoard($boardId);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $section = $this->sectionService->create([
            'board_id' => $boardId,
            'name'     => $validated['name'],
        ]);

        return response()->json($section, 201);
    }
}
