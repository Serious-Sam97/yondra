<?php

namespace App\Http\Middleware;

use App\Services\CardHistory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One API request = one action in card history (CardHistory::batch). On every API
 * route; webhook routes add a source, e.g. `card.history:webhook,whatsapp`, so
 * their entries read "via WhatsApp".
 */
class RecordCardHistory
{
    public function handle(Request $request, Closure $next, ?string $source = null, ?string $via = null): Response
    {
        $run = fn () => CardHistory::batch(fn () => $next($request));

        return $source ? CardHistory::as($source, $run, $via) : $run();
    }
}
