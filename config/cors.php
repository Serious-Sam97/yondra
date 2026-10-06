<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'register'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['http://localhost:3000', 'http://localhost:3001', 'http://localhost:3010', 'https://yondra-thunder.vercel.app', 'https://yondra.net', 'https://yondra-vortex.yondra.net'],

    // Any localhost port is allowed in dev (covers Vortex on :3010 and future tools).
    // Any yondra.net subdomain is allowed in prod (covers Vortex and future admin tools).
    'allowed_origins_patterns' => ['#^http://localhost:\d+$#', '#^https://[a-z0-9-]+\.yondra\.net$#'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
