<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vortex — private admin surface
    |--------------------------------------------------------------------------
    |
    | admin_emails: comma-separated allowlist. When non-empty, a user must have
    | is_admin = true AND an email on this list to pass the vortex.admin gate.
    | When empty, the is_admin column alone decides.
    |
    */

    'admin_emails' => env('VORTEX_ADMIN_EMAILS', ''),

    'sql_timeout_ms' => (int) env('VORTEX_SQL_TIMEOUT_MS', 5000),

    'sql_max_rows' => (int) env('VORTEX_SQL_MAX_ROWS', 500),

];
