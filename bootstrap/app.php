<?php

use App\Http\Middleware\EnsureVortexAdmin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Attaches the 'api' rate limiter (defined in AppServiceProvider) to every API route.
        $middleware->throttleApi();

        $middleware->alias([
            'vortex.admin' => EnsureVortexAdmin::class,
        ]);

        $middleware->group('web', [
            EnsureFrontendRequestsAreStateful::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AddQueuedCookiesToResponse::class,
            VerifyCsrfToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Vortex Anomalies (YON-74): fold every reported exception into the error
        // monitor. Returns void so Laravel's default logging still runs; the
        // recorder is self-guarding (never throws, never recurses).
        $exceptions->report(function (Throwable $e) {
            app(\App\Services\Monitoring\ErrorRecorder::class)->record($e);
        });
    })->create();
