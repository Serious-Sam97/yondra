<?php

return [
    // Shared with the world-server: it verifies the short-lived world tokens we mint.
    'world_secret' => env('YUTOPIA_WORLD_SECRET'),
    // Signs world-server → Laravel internal calls (presence, objects, Vortex lines).
    'internal_secret' => env('YUTOPIA_INTERNAL_SECRET'),
    'world_url' => env('YUTOPIA_WORLD_URL', 'ws://localhost:2567'),
    'client_url' => env('YUTOPIA_CLIENT_URL', 'http://localhost:3100'),
    'token_ttl' => (int) env('YUTOPIA_TOKEN_TTL', 600),

    'livekit' => [
        'url' => env('LIVEKIT_URL', 'ws://localhost:7880'),
        'key' => env('LIVEKIT_API_KEY'),
        'secret' => env('LIVEKIT_API_SECRET'),
    ],
];
