<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S-06 · THE SOUL SIMULATOR (internal). Lives a Vortex's life at speed for a
 * user profile — the absent one, the night owl, the gamer, the kind one, the
 * rude one — hour by hour through the real SoulService and EconomyService,
 * and reports one snapshot per day. Everything runs inside a transaction that
 * is always rolled back, with the clock faked and restored: nothing persists.
 */
final class SimulatorService
{
    /** per profile: is this local hour a session? and what happens in one hour of it */
    public const PROFILES = [
        'absent' => ['label' => 'the absent one (one hour every six days)'],
        'night' => ['label' => 'the night owl (22h–02h, every day)'],
        'gamer' => ['label' => 'the gamer (lunch + evening, games and snacks)'],
        'kind' => ['label' => 'the kind one (office hours, feeds him, says thanks)'],
        'rude' => ['label' => 'the rude one (office hours, ignores and insults)'],
    ];

    public function __construct(private readonly SoulService $souls, private readonly EconomyService $econ) {}

    private function session(string $profile, CarbonImmutable $t): ?array
    {
        $h = (int) $t->format('G');
        $weekday = $t->isWeekday();

        return match ($profile) {
            'absent' => ($t->dayOfYear % 6 === 0 && $h === 10) ? [['type' => 'visit'], ['type' => 'card_done']] : null,
            'night' => ($h >= 22 || $h < 2) ? [['type' => 'visit'], ['type' => 'chat'], ['type' => 'chat'], ['type' => 'card_done'], ['type' => 'night']] : null,
            'gamer' => (($h >= 12 && $h < 14) || ($h >= 19 && $h < 22)) ? [['type' => 'visit'], ['type' => 'game'], ['type' => 'game'], ['type' => 'fed'], ['type' => 'compliment']] : null,
            'kind' => ($weekday && $h >= 9 && $h < 17) ? [['type' => 'visit'], ['type' => 'card_done'], ['type' => 'compliment'], ['type' => 'care'], ...($h % 3 === 0 ? [['type' => 'fed']] : [])] : null,
            'rude' => ($weekday && $h >= 10 && $h < 16) ? [['type' => 'visit'], ['type' => 'rude'], ['type' => 'ignored'], ['type' => 'card_done']] : null,
            default => null,
        };
    }

    /** @return list<array<string, mixed>> one row per simulated day */
    public function run(string $profile, int $days): array
    {
        $days = max(1, min(90, $days));
        $start = CarbonImmutable::now()->startOfDay();
        $rows = [];
        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => 'Sim '.ucfirst($profile),
                'email' => 'sim-'.Str::lower(Str::random(12)).'@vortex.invalid',
                'password' => Str::random(32),
            ]);
            $this->freeze($start);
            $soul = $this->souls->for($user);
            $s = $soul->state;
            $s['tz'] = 'UTC';
            $soul->state = $s;
            $soul->save();

            for ($hour = 1; $hour <= $days * 24; $hour++) {
                $t = $start->addHours($hour);
                $this->freeze($t);
                $events = $this->session($profile, $t);
                if ($events !== null) {
                    $soul = $this->souls->apply($soul->fresh(), $events);
                    $this->econ->earn($soul, 'active', 60);
                } else {
                    $this->souls->tick($soul->fresh(), $t);
                }
                if ($hour % 24 === 0) {
                    $soul = $soul->fresh();
                    $st = $soul->state;
                    $rows[] = [
                        'day' => intdiv($hour, 24),
                        'needs' => array_map(fn ($v) => (int) round($v), $st['needs'] ?? []),
                        'corruption' => (int) ($st['corruption'] ?? 0),
                        'stage' => SoulService::stage((int) ($st['corruption'] ?? 0)),
                        'proximity' => (int) ($st['proximity'] ?? 0),
                        'relation' => (int) $soul->relation,
                        'deaths' => (int) ($st['deaths'] ?? 0),
                        'sick' => (bool) ($st['sick'] ?? false),
                        'traits' => $st['traits'] ?? [],
                        'balance' => $this->econ->balance($user->id),
                    ];
                }
            }
        } finally {
            DB::rollBack();
            $this->freeze(null);
        }

        return $rows;
    }

    private function freeze(?CarbonImmutable $t): void
    {
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
    }
}
