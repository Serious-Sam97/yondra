<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Q-08 · API errors get a "vortex" field with a comment from him. Purely
 * additive (nothing else in the body changes) and off with `X-Vortex: off`.
 */
class VortexErrorComments
{
    private const LINES = [
        400 => 'that request was malformed. like your backlog.',
        401 => 'who are you. no, really. log in.',
        403 => 'not yours. hands off.',
        404 => 'nothing here. i looked. i\'m very thorough. i\'m a ghost.',
        409 => 'two things want to be the same thing. pick one.',
        422 => 'you sent something i can\'t use. bold.',
        429 => 'slow down. even i get tired. i don\'t. but you should.',
        500 => 'that one\'s on us. the tape snagged.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400
            || strtolower((string) $request->header('X-Vortex')) === 'off') {
            return $response;
        }
        $data = $response->getData(true);
        if (! is_array($data) || array_is_list($data) || isset($data['vortex'])) {
            return $response;
        }
        $code = $response->getStatusCode();
        $data['vortex'] = self::LINES[$code] ?? ($code >= 500 ? self::LINES[500] : 'no.');
        $response->setData($data);

        return $response;
    }
}
