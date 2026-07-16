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
use App\Services\Ai\AiDriver;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Ai\FallbackAiDriver;
use App\Services\Whatsapp\BspDriver;
use App\Services\Whatsapp\MetaCloudDriver;
use App\Services\Whatsapp\WhatsappDriver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\RateLimiter;
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

        // Default WhatsApp driver (config-selected). Per-board overrides are resolved
        // by WhatsappService::driverFor(); this binding serves board-less contexts.
        $this->app->bind(WhatsappDriver::class, function () {
            return config('services.whatsapp.driver') === 'bsp'
                ? $this->app->make(BspDriver::class)
                : $this->app->make(MetaCloudDriver::class);
        });

        // AI provider chain (runtime, DB-controlled). Everything that talks to an LLM depends
        // on the AiDriver interface; here we resolve the settings row, re-assert its non-secret
        // knobs onto config, and build an ordered FallbackAiDriver from the enabled+configured
        // chain. Kept a `bind` (not singleton) so this re-runs — and re-applies settings — on
        // every resolution; a Vortex settings change is picked up on the next request/job with
        // no worker restart. See AiSettingsResolver.
        $this->app->bind(AiDriver::class, function ($app) {
            $resolver = $app->make(AiSettingsResolver::class);
            $settings = $resolver->resolve();
            $resolver->apply($settings);

            $members = array_map(
                fn (string $key) => $app->make($resolver->driverClassFor($key)),
                $resolver->chainKeys($settings),
            );

            // Load-balance mode adds a per-attempt latency budget; off → plain error failover.
            $balance = $settings['balance'] ?? ['enabled' => false, 'timeout' => 5];
            $balanceTimeout = ($balance['enabled'] ?? false) ? (int) $balance['timeout'] : null;

            return new FallbackAiDriver($members, $balanceTimeout);
        });
    }

    public function boot(): void
    {
        // API resources serialize bare (no "data" envelope) — the SPA is typed
        // against the unwrapped shapes.
        JsonResource::withoutWrapping();

        // Global API ceiling (generous — the SPA stays well under it) plus a strict
        // per-IP limiter for the credential endpoints to stop brute force/enumeration.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
        // AI endpoints each cost an outbound LLM call (tokens/money) — keep a tight
        // per-user ceiling so a stuck client or abuse can't run up the bill.
        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

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
            $base = rtrim(config('app.frontend_url'), '/');
            $url = $base.'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
            $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            return (new MailMessage)
                ->subject('Yondra - Reset Password Notification')
                ->view('emails.reset-password', [
                    'url' => $url,
                    'expire' => $expire,
                    'name' => $notifiable->name ?? null,
                ]);
        });
    }
}
