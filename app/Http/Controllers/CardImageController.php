<?php
namespace App\Http\Controllers;

use App\Infrastructure\Models\CardImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CardImageController extends Controller
{
    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'], // 5MB
        ]);

        $file = $request->file('image');
        $path = $file->store("cards/{$card->id}", 'public');
        $position = CardImage::where('card_id', $card->id)->max('position') + 1;

        $image = CardImage::create([
            'card_id'       => $card->id,
            'user_id'       => Auth::id(),
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'position'      => $position,
        ]);

        return response()->json($image->load('uploader:id,name'), 201);
    }

    public function destroy(int $boardId, int $cardId, int $imageId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);

        $image = CardImage::where('card_id', $card->id)->findOrFail($imageId);
        Storage::disk('public')->delete($image->path);
        $image->delete();

        return response()->json(null, 204);
    }
}
