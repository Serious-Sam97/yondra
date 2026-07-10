<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Infrastructure\Models\Board;
use Illuminate\Support\Facades\Http;

/**
 * Direct Meta Cloud API driver — POSTs to
 * {base}/{version}/{phone_number_id}/messages with a Bearer token.
 */
class MetaCloudDriver extends CloudApiDriver
{
    protected function dispatch(Board $board, array $payload): SendResult
    {
        $phoneId = $board->whatsapp_phone_number_id ?: config('services.whatsapp.meta.phone_number_id');
        $token = $board->whatsapp_token ?: config('services.whatsapp.meta.token');

        if (! $phoneId || ! $token) {
            return SendResult::fail('WhatsApp (Meta) is not configured for this board.');
        }

        $base = rtrim((string) config('services.whatsapp.meta.base_url'), '/');
        $ver = config('services.whatsapp.meta.version');

        $res = Http::withToken($token)->timeout(10)
            ->post("{$base}/{$ver}/{$phoneId}/messages", $payload);

        if (! $res->successful()) {
            return SendResult::fail((string) ($res->json('error.message') ?? 'WhatsApp send failed.'));
        }

        return SendResult::ok(data_get($res->json(), 'messages.0.id'));
    }
}
