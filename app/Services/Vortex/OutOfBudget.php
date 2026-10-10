<?php

declare(strict_types=1);

namespace App\Services\Vortex;

/** T-06 · thrown by AiBudget::spend(); callers fall back to their templates. */
final class OutOfBudget extends \RuntimeException
{
    public function __construct(public readonly string $feature)
    {
        parent::__construct("vortex AI budget exhausted: {$feature}");
    }
}
