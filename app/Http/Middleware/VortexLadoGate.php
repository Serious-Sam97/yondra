<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-11 · a side of MK-V that's switched off doesn't exist: its /api/mascot
 * routes answer 404 (the client also stops mounting it, via /mascot/flags).
 */
class VortexLadoGate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (preg_match('#^api/mascot/([a-z0-9-]+)#', $request->path(), $m) === 1) {
            $lado = config('vortex_mk5.routes.'.$m[1]);
            if ($lado !== null && ! config('vortex_mk5.lados.'.$lado, true)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
