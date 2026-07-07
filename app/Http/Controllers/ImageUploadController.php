<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ImageUploadController extends Controller
{
    // Board-scoped image upload for inline rich-text images (description/comments).
    // Not tied to a card, so it works while a new card is still being composed.
    public function store(Request $request, int $boardId)
    {
        $this->authorizeWrite($boardId);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'], // 5MB
        ]);

        $path = $request->file('image')->store("boards/{$boardId}", 'public');

        // Return a host-less path so the client can resolve it against whatever API
        // origin it is configured with (NEXT_PUBLIC_API) — not the backend's APP_URL.
        return response()->json(['url' => '/storage/' . $path], 201);
    }
}
