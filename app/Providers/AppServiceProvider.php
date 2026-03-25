<?php

namespace App\Providers;

use App\Domain\Repository\BoardRepository;
use App\Domain\Repository\CardRepository;
use App\Domain\Repository\SectionRepository;
use App\Infrastructure\Repository\BoardModelRepository;
use App\Infrastructure\Repository\CardModelRepository;
use App\Infrastructure\Repository\SectionModelRepository;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BoardRepository::class, BoardModelRepository::class);
        $this->app->bind(CardRepository::class, CardModelRepository::class);
        $this->app->bind(SectionRepository::class, SectionModelRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app('router')->middlewareGroup('web', [
            EnsureFrontendRequestsAreStateful::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AddQueuedCookiesToResponse::class,
            VerifyCsrfToken::class,
        ]);
    }
}
