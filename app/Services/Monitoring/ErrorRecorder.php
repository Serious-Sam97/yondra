<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Infrastructure\Models\ErrorGroup;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The one place errors are persisted for the Vortex "Anomalies" panel (YON-74).
 *
 * Backend exceptions arrive via the report() hook in bootstrap/app.php; frontend
 * JS errors arrive via the public ingest webhook (recordClient). Both are reduced
 * to a stable `fingerprint` (class + normalised message + origin frame) and folded
 * into an ErrorGroup — same fingerprint ⇒ same group, occurrences_count++, latest
 * hit appended as an ErrorOccurrence.
 *
 * A monitor must never make things worse, so every public entry point is wrapped
 * so it can neither throw (a failure to record is swallowed) nor recurse (a static
 * re-entrancy flag stops an error *raised while recording* from re-entering).
 */
class ErrorRecorder
{
    /** Guards against recording an error that is itself raised while recording. */
    private static bool $recording = false;

    /**
     * Record a backend exception. Request context (route / user / ip) is gathered
     * here so the report() hook stays a one-liner. Never throws.
     */
    public function record(Throwable $e, string $source = 'backend', array $context = []): void
    {
        $this->guard(function () use ($e, $source, $context) {
            if ($this->isIgnored($e)) {
                return;
            }

            $this->persist([
                'source' => $source,
                'level' => 'error',
                'class' => $e::class,
                'message' => $this->cleanMessage($e->getMessage(), $e::class),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'stack' => $this->stackFromThrowable($e),
                'context' => array_merge($this->requestContext(), $context),
            ]);
        });
    }

    /**
     * Record a client-side (browser) error from the ingest webhook. Never throws.
     *
     * @param  array{name?:string,message?:string,stack?:string,url?:string,level?:string,context?:array<string,mixed>}  $payload
     */
    public function recordClient(array $payload): void
    {
        $this->guard(function () use ($payload) {
            $class = trim((string) ($payload['name'] ?? 'Error')) ?: 'Error';
            $message = trim((string) ($payload['message'] ?? ''));
            $stack = isset($payload['stack']) ? (string) $payload['stack'] : '';

            if ($message === '' && $stack === '') {
                return; // nothing actionable
            }

            $level = in_array($payload['level'] ?? '', ['error', 'warning', 'info'], true)
                ? $payload['level']
                : 'error';

            $this->persist([
                'source' => 'frontend',
                'level' => $level,
                'class' => mb_substr($class, 0, 255),
                'message' => $message !== '' ? $message : explode("\n", $stack)[0],
                // The browser URL stands in for the "origin frame" of a JS error.
                'file' => isset($payload['url']) ? mb_substr((string) $payload['url'], 0, 255) : null,
                'line' => null,
                'stack' => $stack !== '' ? [['raw' => mb_substr($stack, 0, 8000)]] : null,
                'context' => array_merge(
                    ['url' => $payload['url'] ?? null],
                    is_array($payload['context'] ?? null) ? $payload['context'] : [],
                ),
            ]);
        });
    }

    /**
     * Fold a normalised error into its group + append an occurrence. Assumes the
     * caller holds the re-entrancy guard.
     *
     * @param  array{source:string,level:string,class:string,message:string,file:?string,line:?int,stack:?array,context:array}  $data
     */
    private function persist(array $data): void
    {
        $now = now();
        $fingerprint = $this->fingerprint($data['class'], $data['message'], $data['file'], $data['line']);
        $message = mb_substr($data['message'], 0, 2000);

        $group = ErrorGroup::firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'source' => $data['source'],
                'level' => $data['level'],
                'exception_class' => $data['class'],
                'message' => $message,
                'file' => $data['file'] !== null ? mb_substr($data['file'], 0, 255) : null,
                'line' => $data['line'],
                'status' => 'open',
                'occurrences_count' => 0,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
        );

        // Refresh the representative message + recency, and auto-reopen a resolved
        // group that has recurred (mirrors Bugsnag). An explicitly *ignored* group
        // stays muted — the admin silenced it on purpose.
        $group->message = $message;
        $group->last_seen_at = $now;
        if ($group->status === 'resolved') {
            $group->status = 'open';
            $group->resolved_at = null;
        }
        $group->save();
        $group->increment('occurrences_count');

        $group->occurrences()->create([
            'message' => $message,
            'stack' => $data['stack'],
            'context' => $data['context'] ?: null,
            'environment' => app()->environment(),
            'occurred_at' => $now,
        ]);

        $this->prune($group);
    }

    /** Keep only the newest N occurrences of a group (config('monitoring.occurrence_cap')). */
    private function prune(ErrorGroup $group): void
    {
        $cap = (int) config('monitoring.occurrence_cap', 50);
        if ($cap < 1 || $group->occurrences_count <= $cap) {
            return;
        }

        $keep = $group->occurrences()
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->take($cap)->pluck('id');

        $group->occurrences()->whereNotIn('id', $keep)->delete();
    }

    /** sha1 of the grouping key: class + volatility-stripped message + origin frame. */
    private function fingerprint(string $class, string $message, ?string $file, ?int $line): string
    {
        return sha1($class.'|'.$this->normaliseMessage($message).'|'.$file.':'.$line);
    }

    /**
     * Strip the volatile parts of a message so "User 42 not found" and
     * "User 99 not found" collapse into one group.
     */
    private function normaliseMessage(string $message): string
    {
        $m = mb_strtolower($message);
        $m = preg_replace('/0x[0-9a-f]+/', '0xaddr', $m) ?? $m;
        $m = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', 'uuid', $m) ?? $m;
        $m = preg_replace('/\d+/', 'n', $m) ?? $m;

        return trim(mb_substr($m, 0, 500));
    }

    /** Drop the class name Laravel prefixes onto some messages, then trim. */
    private function cleanMessage(string $message, string $class): string
    {
        $message = trim($message);

        return $message !== '' ? $message : $class;
    }

    /**
     * Normalise a throwable's trace into a compact, JSON-friendly frame list, the
     * throw site first. Capped so a deep recursion can't bloat the row.
     *
     * @return list<array{file:?string,line:?int,function:?string,class:?string}>
     */
    private function stackFromThrowable(Throwable $e): array
    {
        $frames = [[
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'function' => '(throw)',
            'class' => null,
        ]];

        foreach (array_slice($e->getTrace(), 0, 40) as $f) {
            $frames[] = [
                'file' => isset($f['file']) ? (string) $f['file'] : null,
                'line' => isset($f['line']) ? (int) $f['line'] : null,
                'function' => isset($f['function']) ? (string) $f['function'] : null,
                'class' => isset($f['class']) ? (string) $f['class'] : null,
            ];
        }

        return $frames;
    }

    /**
     * What we can learn about the request behind a backend error. Empty in the
     * console (no HTTP request bound).
     *
     * @return array<string, mixed>
     */
    private function requestContext(): array
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return ['channel' => 'console'];
        }

        $request = request();

        return array_filter([
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route' => optional($request->route())->getName(),
            'user_id' => Auth::id(),
            'ip' => $request->ip(),
        ], fn ($v) => $v !== null);
    }

    /** True when the exception is expected control flow we deliberately don't track. */
    private function isIgnored(Throwable $e): bool
    {
        foreach ((array) config('monitoring.ignore', []) as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** Run $fn under the enabled flag + re-entrancy guard, swallowing any failure. */
    private function guard(callable $fn): void
    {
        if (! config('monitoring.enabled', true) || self::$recording) {
            return;
        }

        self::$recording = true;

        try {
            $fn();
        } catch (Throwable) {
            // A monitor that throws is worse than one that silently misses a hit.
        } finally {
            self::$recording = false;
        }
    }
}
