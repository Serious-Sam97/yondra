<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Tenor proxy for the comment composer's GIF picker. The API key stays server-side
 * (config/services.php ← TENOR_API_KEY); with no key configured the frontend hides
 * the GIF button entirely (see availability), and search answers 503.
 */
class GifController extends Controller
{
    public function availability()
    {
        return response()->json(['enabled' => (bool) config('services.tenor.key')]);
    }

    public function search(Request $request)
    {
        $key = config('services.tenor.key');
        abort_unless($key, 503, 'GIF search is not configured.');

        $q = trim((string) $request->input('q', ''));
        $limit = min(max((int) $request->input('limit', 24), 1), 50);

        $results = Cache::remember(
            'gifs:'.md5($q.'|'.$limit),
            300,
            function () use ($key, $q, $limit) {
                // No query → Tenor's featured feed (what the picker opens with).
                $endpoint = $q === '' ? 'featured' : 'search';
                $res = Http::timeout(6)->get("https://tenor.googleapis.com/v2/{$endpoint}", [
                    'key' => $key,
                    'q' => $q,
                    'limit' => $limit,
                    'media_filter' => 'gif,tinygif',
                    'contentfilter' => 'medium',
                ]);
                abort_unless($res->ok(), 502, 'GIF search failed.');

                // Slim shape: tinygif previews keep the grid light, gif is inserted.
                return collect($res->json('results') ?? [])
                    ->map(fn (array $r) => [
                        'id' => $r['id'] ?? '',
                        'description' => $r['content_description'] ?? 'GIF',
                        'preview_url' => $r['media_formats']['tinygif']['url'] ?? null,
                        'gif_url' => $r['media_formats']['gif']['url'] ?? null,
                    ])
                    ->filter(fn (array $g) => $g['gif_url'] && $g['preview_url'])
                    ->values()
                    ->all();
            },
        );

        return response()->json($results);
    }
}
