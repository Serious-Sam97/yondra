<?php

namespace App\Services\Yutopia;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YutopiaSpace;
use RuntimeException;

// Mints the two short-lived tokens a Yutopia client needs: one for the world-server
// (positions) and one for LiveKit (voice/video). Both are plain HS256 JWTs, so no
// extra composer dependency is needed.
class YutopiaTokens
{
    public static function jwt(array $claims, string $secret): string
    {
        $segments = [
            self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])),
            self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $segments[] = self::b64(hash_hmac('sha256', implode('.', $segments), $secret, true));

        return implode('.', $segments);
    }

    public static function decode(string $jwt, string $secret): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true));
        if (! hash_equals($expected, $parts[2])) {
            return null;
        }
        $claims = json_decode(self::unb64($parts[1]), true);
        if (! is_array($claims) || ($claims['exp'] ?? 0) < time()) {
            return null;
        }

        return $claims;
    }

    public function world(User $user, YutopiaSpace $space, array $avatar): string
    {
        $now = time();

        return self::jwt([
            'iss' => 'yondra',
            'aud' => 'yutopia-world',
            'sub' => (string) $user->id,
            'name' => $user->name,
            'avatar' => $avatar,
            'space_id' => $space->id,
            'project_id' => $space->project_id,
            'roles' => $space->isBuildableBy($user->id) ? ['member', 'builder'] : ['member'],
            'iat' => $now,
            'exp' => $now + config('yutopia.token_ttl'),
        ], $this->secret('yutopia.world_secret'));
    }

    // The world-server decides who hears whom (distance, private areas, stage).
    // Each client only allows its tracks to the people the server says may hear
    // it (LiveKit publisher permissions, enforced at the SFU), so a tampered
    // client can't listen in on a booth.
    public function livekit(User $user, YutopiaSpace $space): string
    {
        $now = time();

        return self::jwt([
            'iss' => $this->secret('yutopia.livekit.key'),
            'sub' => (string) $user->id,
            'name' => $user->name,
            'nbf' => $now - 5,
            'exp' => $now + 6 * 3600,
            'video' => [
                'room' => self::room($space),
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ], $this->secret('yutopia.livekit.secret'));
    }

    public static function room(YutopiaSpace $space): string
    {
        return 'space-'.$space->id;
    }

    private function secret(string $key): string
    {
        $value = config($key);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Missing config {$key}");
        }

        return $value;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/'));
    }
}
