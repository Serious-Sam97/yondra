<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return [

    /*
    |--------------------------------------------------------------------------
    | Vortex Anomalies — error monitor (YON-74)
    |--------------------------------------------------------------------------
    |
    | Config for App\Services\Monitoring\ErrorRecorder, the one place backend and
    | frontend errors are persisted for the Vortex Anomalies panel.
    |
    */

    // Master switch. When false the recorder is a no-op (the report() hook still
    // registers but records nothing). Disabled under phpunit so unrelated feature
    // tests don't persist their expected exceptions; recorder tests flip it on.
    'enabled' => (bool) env('ERROR_MONITOR_ENABLED', true),

    // Newest occurrences retained per group. A hot bug keeps a recent window for
    // inspection without growing the table unbounded.
    'occurrence_cap' => (int) env('ERROR_MONITOR_OCCURRENCE_CAP', 50),

    // Shared secret guarding the public frontend-ingest webhook. Unset ⇒ ingest
    // disabled (every token 404s). Not a per-tenant token — one app-wide value.
    'ingest_token' => env('TELEMETRY_INGEST_TOKEN'),

    // Exception types (and their subclasses) that are expected control flow, not
    // faults — skipped so the panel isn't drowned by 404s and validation errors.
    'ignore' => [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        NotFoundHttpException::class,
        ThrottleRequestsException::class,
    ],

];
