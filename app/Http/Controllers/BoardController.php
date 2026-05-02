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

        $board = $this->boardService->create($validated);

        return response()->json($board, 201);
    }
}
