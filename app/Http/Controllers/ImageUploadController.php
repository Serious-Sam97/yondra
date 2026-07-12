<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        // Private disk + signed streaming URL: inline images used to land on the
        // public disk where anyone with the /storage/... URL could read them.
        // Compatibility: files uploaded before this change stay on the public disk
        // and their /storage/... URLs (baked into stored HTML descriptions and
        // comments) keep working — they were already exposed. Only NEW uploads
        // are private.
        $path = $request->file('image')->store("boards/{$boardId}", 'local');

        // Return a host-less URL so the client can resolve it against whatever API
        // origin it is configured with (NEXT_PUBLIC_API) — not the backend's APP_URL.
        // The signature is NON-expiring (URL::signedRoute, not temporarySignedRoute)
        // on purpose: the editor embeds this exact URL into stored HTML, so an
        // expiring link would rot the content. It is still an unguessable
        // capability URL, strictly better than the old world-readable /storage path.
        return response()->json([
            'url' => URL::signedRoute('inline-images.show', ['path' => $path], absolute: false),
        ], 201);
    }

    /**
     * Stream a private inline image. Signature-gated only (`signed:relative`) —
     * <img> tags cannot send Bearer tokens, so possession of the signed URL
     * (handed out only to users who passed board write authorization, then
     * embedded in board-visible HTML) is the access capability.
     */
    public function show(Request $request): StreamedResponse
    {
        $path = (string) $request->query('path');

        // The signature already pins the exact path we issued, but keep a belt-and-
        // braces guard against traversal and off-prefix reads.
        abort_if(str_contains($path, '..') || ! str_starts_with($path, 'boards/'), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }
}
