<?php

namespace App\Providers;

use App\Domain\Repository\BoardRepository;
use App\Domain\Repository\CardRepository;
use App\Domain\Repository\ProjectRepository;
use App\Domain\Repository\SectionRepository;
use App\Domain\Repository\TagRepository;
use App\Infrastructure\Repository\BoardModelRepository;
use App\Infrastructure\Repository\CardModelRepository;
use App\Infrastructure\Repository\ProjectModelRepository;
use App\Infrastructure\Repository\SectionModelRepository;
use App\Infrastructure\Repository\TagModelRepository;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BoardRepository::class, BoardModelRepository::class);
        $this->app->bind(CardRepository::class, CardModelRepository::class);
        $this->app->bind(ProjectRepository::class, ProjectModelRepository::class);
        $this->app->bind(SectionRepository::class, SectionModelRepository::class);
        $this->app->bind(TagRepository::class, TagModelRepository::class);
    }

    public function boot(): void
    {
        app('router')->middlewareGroup('web', [
            EnsureFrontendRequestsAreStateful::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AddQueuedCookiesToResponse::class,
            VerifyCsrfToken::class,
        ]);

        // Point the password-reset email link at the SPA frontend instead of the API host.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $base = rtrim(config('app.frontend_url'), '/');
            return $base.'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset());
        });

        // Custom subject + Yondra-branded HTML for the password-reset email.
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $base   = rtrim(config('app.frontend_url'), '/');
            $url    = $base.'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
            $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            return (new MailMessage)
                ->subject('Yondra - Reset Password Notification')
                ->view('emails.reset-password', [
                    'url'    => $url,
                    'expire' => $expire,
                    'name'   => $notifiable->name ?? null,
                ]);
        });
    }
}
