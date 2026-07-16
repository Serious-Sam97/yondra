<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Chain-of-responsibility over an ordered list of concrete drivers: try the first, and on
 * failure fall through to the next configured provider. Composed from the settings chain in
 * AppServiceProvider. Feature code still depends only on AiDriver — it never knows a chain
 * is behind it.
 *
 * Streaming has one subtlety: once a provider has emitted a token we can't cleanly restart
 * the stream on another, so failover only happens BEFORE the first delta. A mid-stream drop
 * rethrows and surfaces as the usual ai.error. Against SseAiDriver this is exactly the right
 * line: unconfigured / connection-refused / non-2xx all throw before any delta and fail over;
 * only a broken read after tokens have flowed rethrows.
 *
 * Optional load-balancing: with a $balanceTimeout set, every non-final provider is given that
 * many seconds to start responding (via the transient services.ai.attempt_deadline config the
 * drivers read); a slower one is aborted and the chain moves on. The final provider always
 * runs unbounded — there's nothing left to fall to. Null $balanceTimeout = plain error-only
 * failover.
 */
class FallbackAiDriver implements AiDriver
{
    /**
     * @param  list<AiDriver>  $members  Ordered providers to try.
     * @param  int|null  $balanceTimeout  Per-attempt latency budget in seconds, or null to disable.
     */
    public function __construct(private array $members, private ?int $balanceTimeout = null) {}

    /** @return list<AiDriver> */
    public function members(): array
    {
        return $this->members;
    }

    public function isAvailable(): bool
    {
        foreach ($this->members as $member) {
            if ($member->isAvailable()) {
                return true;
            }
        }

        return false;
    }

    public function streamChat(string $system, array $messages, callable $onDelta, int $maxTokens = 700): string
    {
        $available = $this->available();
        $lastIndex = count($available) - 1;
        $last = null;

        foreach ($available as $i => $member) {
            $emitted = false;
            $wrapped = function (string $delta) use (&$emitted, $onDelta) {
                $emitted = true;
                $onDelta($delta);
            };

            try {
                return $this->attempt(
                    $i === $lastIndex,
                    fn () => $member->streamChat($system, $messages, $wrapped, $maxTokens),
                );
            } catch (Throwable $e) {
                if ($emitted) {
                    // Tokens already streamed to the client — can't restart on another provider.
                    throw $e;
                }
                Log::warning('AI provider failed before streaming; falling through', [
                    'provider' => $member::class,
                    'error' => $e->getMessage(),
                ]);
                $last = $e;
            }
        }

        throw $last ?? new RuntimeException('No AI provider is available.');
    }

    public function complete(string $system, array $messages, int $maxTokens = 1024, bool $json = false): string
    {
        $available = $this->available();
        $lastIndex = count($available) - 1;
        $last = null;

        foreach ($available as $i => $member) {
            try {
                return $this->attempt(
                    $i === $lastIndex,
                    fn () => $member->complete($system, $messages, $maxTokens, $json),
                );
            } catch (Throwable $e) {
                Log::warning('AI provider failed; falling through', [
                    'provider' => $member::class,
                    'error' => $e->getMessage(),
                ]);
                $last = $e;
            }
        }

        throw $last ?? new RuntimeException('No AI provider is available.');
    }

    /** @return list<AiDriver> Only the members that are currently usable. */
    private function available(): array
    {
        return array_values(array_filter($this->members, fn (AiDriver $m) => $m->isAvailable()));
    }

    /**
     * Run one provider attempt, publishing the balance deadline (for all but the final
     * provider) via config so the driver's transport picks it up, then restoring it.
     */
    private function attempt(bool $isLast, callable $fn): string
    {
        $deadline = ($this->balanceTimeout && ! $isLast) ? $this->balanceTimeout : null;
        $previous = config('services.ai.attempt_deadline');
        Config::set('services.ai.attempt_deadline', $deadline);

        try {
            return $fn();
        } finally {
            Config::set('services.ai.attempt_deadline', $previous);
        }
    }
}
