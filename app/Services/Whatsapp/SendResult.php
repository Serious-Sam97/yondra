<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

/**
 * Outcome of an outbound send, normalized across drivers.
 */
final class SendResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $waMessageId = null,
        public readonly ?string $error = null,
    ) {}

    public static function ok(?string $waMessageId): self
    {
        return new self(true, $waMessageId, null);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, $error);
    }
}
