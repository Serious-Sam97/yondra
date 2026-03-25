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
        $this->sectionService->remove($sectionId);
        return response()->json(null, 204);
    }

    public function store(Request $request, int $boardId)
    {
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
