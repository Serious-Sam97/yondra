<?php

namespace App\Http\Controllers;

use App\Services\TagService;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public TagService $tagService;

    public function __construct()
    {
        $this->tagService = resolve(TagService::class);
    }

    public function store(Request $request, int $boardId)
    {
        $this->authorizeBoard($boardId);
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:50'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $tag = $this->tagService->create([
            'board_id' => $boardId,
            'name'     => $validated['name'],
            'color'    => $validated['color'],
        ]);

        return response()->json($tag, 201);
    }

    public function destroy(int $boardId, int $tagId)
    {
        $this->authorizeBoard($boardId);
        $this->tagService->remove($tagId);
        return response()->json(null, 204);
    }
}
