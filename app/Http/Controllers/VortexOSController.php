<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// Sign in to VortexOS with Yondra, from a computer inside Yutopia
// (yutopia/docs/plan/19-vortexos.md). The person at the desk asks for a
// one-time code (good for 60 s, used once); VortexOS's backend trades it here
// for who they are, over a call signed with HMAC-SHA256 over
// "{timestamp}.{raw body}" with VORTEXOS_SHARED_SECRET, and keeps its own
// account and session for them.
class VortexOSController extends Controller
{
    private const TTL = 60;

    public function handoff(Request $request): JsonResponse
    {
        $code = Str::random(48);
        Cache::put('vortexos:handoff:'.$code, $request->user()->id, self::TTL);

        return response()->json(['code' => $code, 'expiresIn' => self::TTL]);
    }

    // VortexOS's backend → here: the code's person (once), or 404.
    public function redeem(Request $request): JsonResponse
    {
        $this->verify($request);
        $data = $request->validate(['code' => ['required', 'string', 'size:48']]);
        $userId = Cache::pull('vortexos:handoff:'.$data['code']);
        abort_unless($userId, 404, 'Invalid or expired code');
        $user = User::findOrFail($userId);

        return response()->json(['id' => $user->id, 'name' => $user->name]);
    }

    private function verify(Request $request): void
    {
        $secret = (string) config('vortexos.shared_secret');
        $ts = (int) $request->header('X-VortexOS-Timestamp');
        $sig = (string) $request->header('X-VortexOS-Signature');
        abort_if($secret === '' || abs(time() - $ts) > 300, 401);
        $expected = hash_hmac('sha256', $ts.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, $sig), 401);
    }
}
