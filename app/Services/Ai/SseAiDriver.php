<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Shared behaviour for streaming, SSE-based LLM providers. Owns the transport: the
 * `data: {json}` line buffer over a chunked HTTP body, delta assembly, and the
 * configured/HTTP-error guards. A concrete driver only supplies the provider
 * specifics — endpoint, headers, request body, and how to read a text delta out of
 * one decoded frame. Mirrors the WhatsApp CloudApiDriver → MetaCloudDriver shape.
 */
abstract class SseAiDriver implements AiDriver
{
    public function streamChat(string $system, array $messages, callable $onDelta, int $maxTokens = 700): string
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException(static::class.' is not configured.');
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(120)
            ->withOptions(['stream' => true])
            ->post($this->endpoint(), $this->payload($system, $messages, $maxTokens));

        if (! $response->successful()) {
            throw new \RuntimeException(static::class.' returned HTTP '.$response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $full = '';

        // SSE frames are newline-delimited `data: {json}` lines. Read in chunks, process
        // every complete line, and keep the trailing partial for the next read.
        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $json = trim(substr($line, 5));
                if ($json === '' || $json === '[DONE]') {
                    continue;
                }
                $event = json_decode($json, true);
                if (! is_array($event)) {
                    continue;
                }
                $delta = $this->extractDelta($event);
                if ($delta === null || $delta === '') {
                    continue;
                }
                $full .= $delta;
                $onDelta($delta);
            }
        }

        return $full;
    }

    /** The streaming completions endpoint URL. */
    abstract protected function endpoint(): string;

    /** @return array<string,string> Request headers (auth, content-type, versioning). */
    abstract protected function headers(): array;

    /**
     * @param  list<array{role:string,content:string}>  $messages
     * @return array<string,mixed>  The JSON request body (must request streaming).
     */
    abstract protected function payload(string $system, array $messages, int $maxTokens): array;

    /**
     * Pull the text delta out of one decoded SSE `data` frame, or null when the frame
     * carries no text (metadata, ping, message-start, etc).
     *
     * @param  array<string,mixed>  $event
     */
    abstract protected function extractDelta(array $event): ?string;
}
