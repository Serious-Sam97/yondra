<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\CardTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CardTemplateController extends Controller
{
    public function index(int $boardId)
    {
        $this->authorizeBoard($boardId);
        return CardTemplate::where('board_id', $boardId)->latest()->get();
    }

    public function store(Request $request, int $boardId)
    {
        $this->authorizeOwner($boardId);
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'template_data' => ['required', 'array'],
        ]);
        $template = CardTemplate::create([
            'board_id'      => $boardId,
            'user_id'       => Auth::id(),
            'name'          => $validated['name'],
            'template_data' => $validated['template_data'],
        ]);
        return response()->json($template, 201);
    }

    public function destroy(int $boardId, int $templateId)
    {
        $this->authorizeOwner($boardId);
        CardTemplate::where('board_id', $boardId)->findOrFail($templateId)->delete();
        return response()->json(null, 204);
    }
}
