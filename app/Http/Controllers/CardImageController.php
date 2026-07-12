<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\CardImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardImageController extends Controller
{
    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'], // 5MB
        ]);

        // Private disk: the raw file is never web-reachable; the model's `url`
        // accessor hands out a signed streaming URL instead (see show()).
        $file = $request->file('image');
        $path = $file->store("cards/{$card->id}", 'local');
        $position = CardImage::where('card_id', $card->id)->max('position') + 1;

        $image = CardImage::create([
            'card_id' => $card->id,
            'user_id' => Auth::id(),
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'position' => $position,
        ]);

        return response()->json($image->load('uploader:id,name'), 201);
    }

    /**
     * Stream a private card image. Guarded by the `signed:relative` middleware
     * only — no Sanctum — because the frontend renders plain <img src> tags that
     * cannot attach Bearer tokens. The time-limited signature (issued only inside
     * payloads that already passed board authorization) IS the access grant: a
     * capability URL, like a Sanctum-less pre-signed S3 link. Tampered or expired
     * links get 403 from the middleware before this runs.
     */
    public function show(int $imageId): StreamedResponse
    {
        $image = CardImage::findOrFail($imageId);
        $disk = Storage::disk($image->disk ?: 'public');
        abort_unless($disk->exists($image->path), 404);

        return $disk->response($image->path, $image->original_name);
    }

    public function destroy(int $boardId, int $cardId, int $imageId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);

        $image = CardImage::where('card_id', $card->id)->findOrFail($imageId);
        Storage::disk($image->disk ?: 'public')->delete($image->path);
        $image->delete();

        return response()->json(null, 204);
    }
}
