<?php

namespace App\Http\Controllers;

use App\Services\Monitoring\ErrorRecorder;
use Illuminate\Http\Request;

/**
 * Public ingest for browser (frontend) errors, feeding the Vortex "Anomalies"
 * monitor (YON-74). No header auth — the unguessable app-wide TELEMETRY_INGEST_TOKEN
 * in the URL IS the credential (same shape as the intake / QA-CI webhooks). When no
 * token is configured, ingest is disabled and every request 404s.
 */
class ErrorIngestController extends Controller
{
    public function __construct(private readonly ErrorRecorder $recorder) {}

    public function handle(Request $request, string $token)
    {
        $expected = config('monitoring.ingest_token');

        // Constant-time compare; a missing/blank config token means "closed".
        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $token)) {
            return response()->json(['message' => 'Unknown or disabled ingest endpoint.'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'message' => 'sometimes|string|max:4000',
            'stack' => 'sometimes|string|max:16000',
            'url' => 'sometimes|string|max:2000',
            'level' => 'sometimes|in:error,warning,info',
            'context' => 'sometimes|array',
        ]);

        $this->recorder->recordClient($data);

        return response()->json(['ok' => true], 201);
    }
}
