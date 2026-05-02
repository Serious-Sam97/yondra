<?php

namespace App\Http\Controllers;

use App\Services\BoardService;
use Illuminate\Http\Request;

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

        return response()->json($this->boardService->create($validated), 201);
    }

    public function update(Request $request, int $boardId)
    {
        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'project_id'  => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $validated['id'] = $boardId;

        return $this->boardService->edit($validated);
    }
}
