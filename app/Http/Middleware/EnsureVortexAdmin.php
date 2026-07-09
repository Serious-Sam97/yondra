<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVortexAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->is_admin, 403);

        $allow = array_filter(array_map('trim', explode(',', (string) config('vortex.admin_emails'))));

        abort_if($allow !== [] && ! in_array($user->email, $allow, true), 403);

        return $next($request);
    }
}
