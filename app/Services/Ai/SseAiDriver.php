<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Shared behaviour for HTTP LLM providers. Owns both transports: the streaming SSE
 * `data: {json}` line buffer (streamChat) and the non-streaming request (complete). A
 * concrete driver only supplies the provider specifics — endpoint, headers, request
 * body, and how to read text out of a streamed frame vs a whole response. Mirrors the
 * WhatsApp CloudApiDriver → MetaCloudDriver shape.
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
            ->post($this->endpoint(), $this->payload($system, $messages, $maxTokens, true, false));

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

    public function complete(string $system, array $messages, int $maxTokens = 1024, bool $json = false): string
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException(static::class.' is not configured.');
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(120)
            ->post($this->endpoint(), $this->payload($system, $messages, $maxTokens, false, $json));

        if (! $response->successful()) {
            throw new \RuntimeException(static::class.' returned HTTP '.$response->status());
        }

        return $this->extractText($response->json() ?? []);
    }

    /** The completions endpoint URL. */
    abstract protected function endpoint(): string;

    /** @return array<string,string> Request headers (auth, content-type, versioning). */
    abstract protected function headers(): array;

    /**
     * @param  list<array{role:string,content:string}>  $messages
     * @return array<string,mixed>  The JSON request body. $stream requests SSE; $json asks
     *                              for a JSON object where the provider supports it.
     */
    abstract protected function payload(string $system, array $messages, int $maxTokens, bool $stream, bool $json): array;

    /**
     * Pull the text delta out of one decoded SSE `data` frame, or null when the frame
     * carries no text (metadata, ping, message-start, etc).
     *
     * @param  array<string,mixed>  $event
     */
    abstract protected function extractDelta(array $event): ?string;

    /**
     * Pull the full text out of a non-streaming response body.
     *
     * @param  array<string,mixed>  $response
     */
    abstract protected function extractText(array $response): string;
}
