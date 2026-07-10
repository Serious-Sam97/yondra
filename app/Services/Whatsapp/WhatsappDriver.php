<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Infrastructure\Models\Board;

/**
 * Abstraction over a WhatsApp Cloud API transport. Concrete drivers:
 *  - MetaCloudDriver — direct Meta Graph API (graph.facebook.com)
 *  - BspDriver       — a Business Solution Provider (e.g. 360dialog)
 *
 * The message payloads are identical (both speak the Cloud API); drivers differ
 * only in endpoint + auth. The provider is chosen per-board (Board::whatsapp_provider),
 * falling back to config('services.whatsapp.driver').
 */
interface WhatsappDriver
{
    /** Send a free-form text message (only valid inside the 24h service window). */
    public function sendText(Board $board, string $to, string $body): SendResult;

    /**
     * Send a pre-approved template message (valid any time).
     *
     * @param  array<int,array<string,mixed>>  $components  Cloud-API template components
     */
    public function sendTemplate(Board $board, string $to, string $template, string $language, array $components = []): SendResult;

    /** Verify an inbound webhook's HMAC signature against this board's app secret. */
    public function verifySignature(Board $board, string $rawBody, ?string $signatureHeader): bool;
}
