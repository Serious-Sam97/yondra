<?php

/*
 * LADO T · the switches for MK-V in production.
 *
 * lados: each side behind its own flag (T-11), so it can be rolled out slowly
 * and turned off fast. Off = its /api/mascot routes answer 404 and the client
 * never mounts it. Env: VORTEX_MK5_<KEY>=false.
 *
 * ai: the LLM budget (T-06). Units are rough "calls"; every feature has a
 * per-user daily cap and they all share one global daily budget. Past either,
 * the feature falls back to its local template.
 *
 * telemetry: aggregate-only counters, sent only by users who consent (T-07).
 */

$flag = fn (string $k) => filter_var(env('VORTEX_MK5_'.strtoupper($k), true), FILTER_VALIDATE_BOOL);

return [
    'lados' => [
        'abaixo' => $flag('abaixo'),
        'arcade' => $flag('arcade'),
        'economia' => $flag('economia'),
        'radio' => $flag('radio'),
        'lab' => $flag('lab'),
        'multiverso' => $flag('multiverso'),
        'social' => $flag('social'),
        'fora' => $flag('fora'),
        'experimental' => $flag('experimental'),
        'criador' => $flag('criador'),
        'temporadas' => $flag('temporadas'),
        'misterios' => $flag('misterios'),
        'agente' => $flag('agente'),
        'dark' => $flag('dark'),
    ],

    // which /api/mascot/<prefix> belongs to which side
    'routes' => [
        'below' => 'abaixo', 'arcade' => 'arcade', 'econ' => 'economia', 'shop' => 'economia', 'trades' => 'economia',
        'album' => 'economia', 'radio' => 'radio', 'gazette' => 'radio', 'lab' => 'lab', 'dimensions' => 'multiverso',
        'social' => 'social', 'outside' => 'fora', 'calendar' => 'fora', 'terminal' => 'fora', 'creator' => 'criador',
        'story' => 'temporadas', 'great-rewind' => 'temporadas', 'side-c' => 'temporadas', 'ending' => 'temporadas',
        'new-tape' => 'temporadas', 'push' => 'fora', 'help' => 'temporadas', 'fragments' => 'misterios', 'agent' => 'agente',
        'reminders' => 'agente',
    ],

    'ai' => [
        'global_daily' => (int) env('VORTEX_AI_GLOBAL_DAILY', 20000),
        // per user per day
        'features' => [
            'chat' => ['cap' => 120, 'cost' => 1],
            'diary' => ['cap' => 2, 'cost' => 1],
            'letters' => ['cap' => 3, 'cost' => 1],
            'npc' => ['cap' => 40, 'cost' => 1],
            'tarot' => ['cap' => 2, 'cost' => 1],
            'translate' => ['cap' => 30, 'cost' => 1],
            'voidhour' => ['cap' => 2, 'cost' => 2],
            'agent' => ['cap' => 30, 'cost' => 1],
            'memory' => ['cap' => 20, 'cost' => 1],
        ],
    ],

    // Q-03 · Web Push (VAPID). Without keys, push is simply off.
    'push' => [
        'public_key' => (string) env('VORTEX_VAPID_PUBLIC_KEY', ''),
        'private_key' => (string) env('VORTEX_VAPID_PRIVATE_KEY', ''),
        'subject' => (string) env('VORTEX_VAPID_SUBJECT', 'mailto:vortex@yondra.app'),
    ],

    'telemetry' => [
        'retention_days' => 90,
    ],
];
