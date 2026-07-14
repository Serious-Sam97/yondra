<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'github' => [
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        // Optional instance-wide fallback used when a board has no token of its own.
        'token' => env('GITHUB_TOKEN'),
    ],

    'jotform' => [
        // API key used to download files a client attached to an intake form
        // submission (JotForm upload URLs are gated behind ?apiKey=…). Unset =>
        // the intake webhook still creates the card, it just can't pull the files.
        'api_key' => env('JOTFORM_API_KEY'),
    ],

    'whatsapp' => [
        // Driver used when a board doesn't pin its own provider: 'meta' | 'bsp'.
        'driver' => env('WHATSAPP_DRIVER', 'meta'),

        // Approved template used for the WhatsApp notification channel (card #22).
        // One body variable receives the notification's message text. Unset => the
        // channel stays dark (opt-in prefs alone don't send).
        'notification_template' => env('WHATSAPP_NOTIFICATION_TEMPLATE'),
        'notification_language' => env('WHATSAPP_NOTIFICATION_LANGUAGE', 'en'),

        // Direct Meta Cloud API (graph.facebook.com).
        'meta' => [
            'base_url' => env('WHATSAPP_META_BASE_URL', 'https://graph.facebook.com'),
            'version' => env('WHATSAPP_META_VERSION', 'v21.0'),
            // Instance-wide fallbacks; a board overrides each via its own DB columns.
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
            'token' => env('WHATSAPP_TOKEN'),
            'app_secret' => env('WHATSAPP_APP_SECRET'),
            'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        ],

        // Business Solution Provider (e.g. 360dialog waba-v2, Twilio). Same Cloud-API
        // message payloads; only the transport + auth header differ.
        'bsp' => [
            'base_url' => env('WHATSAPP_BSP_BASE_URL', 'https://waba-v2.360dialog.io'),
            'api_key' => env('WHATSAPP_BSP_API_KEY'),
        ],
    ],

    // Card AI assist (streamed thread summaries). Provider-agnostic: usage code depends
    // on the App\Services\Ai\AiDriver interface and the concrete driver is chosen here by
    // 'driver'. To add a provider, extend App\Services\Ai\SseAiDriver and map it in
    // AppServiceProvider — no usage change. With the selected provider unconfigured, the
    // /ai endpoints answer 503 and the "Summarise" affordance stays hidden.
    'ai' => [
        'driver' => env('AI_DRIVER', 'anthropic'),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 700),

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            // Summaries are short; Haiku is a cheaper, faster fit — set ANTHROPIC_MODEL to switch.
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],

        // Groq (groq.com) — OpenAI-compatible chat completions, fast LPU inference.
        'groq' => [
            'api_key' => env('GROQ_API_KEY'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        ],

        // Local Ollama — OpenAI-compatible endpoint; no key needed.
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
            'model' => env('OLLAMA_MODEL', 'llama3.1'),
        ],
    ],

    // GIF picker in the comment composer (proxied server-side; no key → hidden).
    'tenor' => [
        'key' => env('TENOR_API_KEY'),
    ],

];
