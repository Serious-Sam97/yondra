<?php

namespace App\Http\Controllers;

use App\Services\CardService;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public CardService $cardService;

    public function __construct()
    {
        $this->cardService = resolve(CardService::class);
    }

    public function store(Request $request, int $boardId)
    {
        $validated = $request->validate([
            'section_id'  => ['required', 'integer'],
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $card = $this->cardService->create([
            'board_id'    => $boardId,
            'section_id'  => $validated['section_id'],
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? '',
        ]);

        return response()->json($card, 201);
    }

    public function update(Request $request, int $boardId, int $cardId)
    {
        $validated = $request->validate([
            'section_id'  => ['sometimes', 'integer'],
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $card = $this->cardService->edit(array_merge($validated, [
            'id'       => $cardId,
            'board_id' => $boardId,
        ]));

        return response()->json($card);
    }
}
