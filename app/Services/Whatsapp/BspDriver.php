<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Infrastructure\Models\Board;
use Illuminate\Support\Facades\Http;

/**
 * Business Solution Provider driver (360dialog waba-v2 shape): same Cloud-API
 * payloads, POSTed to {base}/messages authenticated with a D360-API-KEY header.
 * The board's `whatsapp_token` column holds the BSP api-key.
 */
class BspDriver extends CloudApiDriver
{
    protected function dispatch(Board $board, array $payload): SendResult
    {
        $apiKey = $board->whatsapp_token ?: config('services.whatsapp.bsp.api_key');

        if (! $apiKey) {
            return SendResult::fail('WhatsApp (BSP) is not configured for this board.');
        }

        $base = rtrim((string) config('services.whatsapp.bsp.base_url'), '/');

        $res = Http::withHeaders(['D360-API-KEY' => $apiKey])->timeout(10)
            ->post("{$base}/messages", $payload);

        if (! $res->successful()) {
            return SendResult::fail((string) ($res->json('error.message') ?? 'WhatsApp send failed.'));
        }

        return SendResult::ok(data_get($res->json(), 'messages.0.id'));
    }
}
