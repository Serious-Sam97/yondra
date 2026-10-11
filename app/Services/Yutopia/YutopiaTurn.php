<?php

namespace App\Services\Yutopia;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Cloudflare TURN credentials for LiveKit's media, so voice/video gets relayed when a
// browser can't reach the SFU directly. Credentials aren't user-bound, so one set is
// cached and shared until well before it expires. Empty list = connect directly.
class YutopiaTurn
{
    public function iceServers(): array
    {
        $keyId = config('yutopia.turn.key_id');
        $token = config('yutopia.turn.api_token');
        if (! $keyId || ! $token) {
            return [];
        }
        $ttl = config('yutopia.turn.ttl');

        return Cache::get('yutopia:turn') ?? $this->fetch($keyId, $token, $ttl);
    }

    private function fetch(string $keyId, string $token, int $ttl): array
    {
        try {
            $res = Http::withToken($token)->timeout(5)
                ->post("https://rtc.live.cloudflare.com/v1/turn/keys/{$keyId}/credentials/generate-ice-servers", ['ttl' => $ttl])
                ->throw();
        } catch (Throwable $e) {
            Log::warning('Yutopia TURN credentials failed', ['error' => $e->getMessage()]);

            return [];
        }

        // Browsers block port 53, and that URL only times out.
        $servers = collect($res->json('iceServers', []))->map(function (array $s) {
            $s['urls'] = array_values(array_filter((array) $s['urls'], fn (string $u) => ! str_contains($u, ':53?') && ! str_ends_with($u, ':53')));

            return $s;
        })->filter(fn (array $s) => $s['urls'])->values()->all();

        // Refresh at half-life so a session started from the cache still has hours left.
        Cache::put('yutopia:turn', $servers, intdiv($ttl, 2));

        return $servers;
    }
}
