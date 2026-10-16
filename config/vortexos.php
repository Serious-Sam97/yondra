<?php

return [
    // Shared with VortexOS's backend (vortex-backend): signs its calls to redeem
    // a "sign in with Yondra" code from a computer in Yutopia. Empty = off.
    'shared_secret' => env('VORTEXOS_SHARED_SECRET'),
];
