<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Infrastructure\Models\Board;

/**
 * Shared behaviour for the two Cloud-API drivers. Builds identical request bodies
 * and verifies the same Meta-style HMAC signature; subclasses implement only the
 * transport (`dispatch`) since endpoint + auth header are all that differ.
 */
abstract class CloudApiDriver implements WhatsappDriver
{
    public function sendText(Board $board, string $to, string $body): SendResult
    {
        return $this->dispatch($board, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    public function sendTemplate(Board $board, string $to, string $template, string $language, array $components = []): SendResult
    {
        $tpl = ['name' => $template, 'language' => ['code' => $language]];
        if ($components) {
            $tpl['components'] = $components;
        }

        return $this->dispatch($board, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $tpl,
        ]);
    }

    /**
     * Both Meta and 360dialog forward Meta's `X-Hub-Signature-256` when an app
     * secret is configured, so signature verification is shared.
     */
    public function verifySignature(Board $board, string $rawBody, ?string $signatureHeader): bool
    {
        $secret = $board->whatsapp_app_secret ?: config('services.whatsapp.meta.app_secret');
        if (! $secret || ! $signatureHeader) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    /** Perform the actual HTTP send. */
    abstract protected function dispatch(Board $board, array $payload): SendResult;
}
