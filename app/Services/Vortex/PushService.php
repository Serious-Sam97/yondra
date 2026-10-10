<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexReminder;
use App\Infrastructure\Models\VortexSoul;
use App\Services\Vortex\Push\PushTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Q-03 · REAL PUSH, with the tab closed. Opt-in per browser; at most ONE push
 * a day per person (a due reminder, a new letter), plus — only if you asked —
 * a single "you up?" at 03:13 your time. In his voice, never more than that.
 */
final class PushService
{
    public function __construct(private readonly PushTransport $transport) {}

    public static function enabled(): bool
    {
        return (string) config('vortex_mk5.push.public_key') !== '' && (string) config('vortex_mk5.push.private_key') !== '';
    }

    public function subscribe(User $user, string $endpoint, string $p256dh, string $auth): void
    {
        DB::table('vortex_push_subscriptions')->updateOrInsert(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            ['user_id' => $user->id, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        DB::table('vortex_push_subscriptions')->where('user_id', $user->id)->where('endpoint_hash', hash('sha256', $endpoint))->delete();
    }

    public function subscribed(User $user): int
    {
        return DB::table('vortex_push_subscriptions')->where('user_id', $user->id)->count();
    }

    /** one push to every browser of this person; false if capped, off or nobody's listening */
    public function send(User $user, string $kind, string $body, string $url = '/dashboard', ?VortexSoul $soul = null): bool
    {
        if (! self::enabled()) {
            return false;
        }
        $subs = DB::table('vortex_push_subscriptions')->where('user_id', $user->id)->get(['endpoint', 'p256dh', 'auth'])
            ->map(fn ($s) => (array) $s)->all();
        if ($subs === []) {
            return false;
        }
        $tz = $soul?->state['tz'] ?? 'UTC';
        $day = CarbonImmutable::now($tz)->toDateString();
        // the 03:13 page has its own once-a-night slot; everything else shares one a day
        $slot = $kind === 'night' ? "vxpush:night:{$user->id}:{$day}" : "vxpush:{$user->id}:{$day}";
        if (! Cache::add($slot, 1, now()->addDays(2))) {
            return false;
        }
        $gone = $this->transport->send($subs, (string) json_encode([
            'title' => 'vortex',
            'body' => mb_substr($body, 0, 160),
            'url' => $url,
            'tag' => "vortex-{$kind}",
        ]));
        if ($gone !== []) {
            DB::table('vortex_push_subscriptions')->whereIn('endpoint_hash', array_map(fn ($e) => hash('sha256', $e), $gone))->delete();
        }

        return true;
    }

    /** the per-minute sweep: due reminders, and 03:13 for those who asked */
    public function tick(): array
    {
        $sent = ['reminder' => 0, 'night' => 0];
        if (! self::enabled()) {
            return $sent;
        }
        $users = DB::table('vortex_push_subscriptions')->distinct()->pluck('user_id');
        foreach (VortexReminder::whereIn('user_id', $users)->whereNull('delivered_at')->whereNull('pushed_at')
            ->where('remind_at', '<=', now())->orderBy('remind_at')->limit(200)->get() as $r) {
            $r->pushed_at = now();
            $r->save();
            if (($u = User::find($r->user_id)) && $this->send($u, 'reminder', "📌 {$r->body}. you asked me to nag you. i'm nagging.", '/dashboard', VortexSoul::where('user_id', $u->id)->first())) {
                $sent['reminder']++;
            }
        }
        foreach (VortexSoul::whereIn('user_id', $users)->get() as $soul) {
            if (! ($soul->state['outside']['push_night'] ?? false)) {
                continue;
            }
            $local = CarbonImmutable::now($soul->state['tz'] ?? 'UTC');
            if ($local->format('H:i') !== '03:13') {
                continue;
            }
            if (($u = User::find($soul->user_id)) && $this->send($u, 'night', 'you up?', '/dashboard', $soul)) {
                $sent['night']++;
            }
        }

        return $sent;
    }
}
