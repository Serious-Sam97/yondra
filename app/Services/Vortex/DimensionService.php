<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexScore;
use App\Infrastructure\Models\VortexSoul;
use Carbon\CarbonImmutable;

/**
 * LADO J · THE MULTIVERSE. Other tapes, other Yondras. Each dimension is a
 * temporary skin over your real data (the client draws it); the server decides
 * which ones you've found (each by something you really did), keeps the time
 * you spend away (hours a day make home unstable until the next morning),
 * hands out contraband on the way back, and guards Dimension Zero — the
 * dangerous shortcut to Side C.
 */
final class DimensionService
{
    /** id => [name, how it's found] */
    public const DIMENSIONS = [
        'y1985' => ['1985', 'own your first fragment'],
        'corporate' => ['corporate', 'meet the twin'],
        'underwater' => ['underwater', 'visit three rooms below'],
        'paper' => ['paper', 'watch three episodes'],
        'soviet' => ['bureau', 'let him build three gadgets'],
        'pixel' => ['8-bit', 'play the arcade five times'],
        'inverted' => ['the b-side', 'cross into the b-side below'],
        'noir' => ['noir', 'listen to 03.13'],
        'novortex' => ['without him', 'earn his reluctant respect (relation 20)'],
        'future' => ['2080', 'raise him to level 10'],
        'baroque' => ['baroque', 'own three costumes'],
        'vex' => ['vex', 'read the card on the monitor'],
    ];

    public function __construct(private readonly EconomyService $econ) {}

    /** @return list<string> */
    public function found(User $user, VortexSoul $soul): array
    {
        $s = $soul->state ?? [];
        $frags = $s['fragments'] ?? [];
        $rule = [
            'y1985' => count($frags) >= 1,
            'corporate' => ($s['counters']['twin'] ?? 0) >= 1 || ($s['swapped'] ?? false),
            'underwater' => count($s['below']['visited'] ?? []) >= 3,
            'paper' => count($s['story']['seen'] ?? []) >= 3,
            'soviet' => (int) ($s['lab']['built'] ?? 0) >= 3,
            'pixel' => VortexScore::where('user_id', $user->id)->count() >= 5,
            'inverted' => in_array('ladob', $s['below']['visited'] ?? [], true),
            'noir' => isset($s['radio_on_at']),
            'novortex' => (int) $soul->relation >= 20,
            'future' => $this->econ->level($soul)['level'] >= 10,
            'baroque' => count(array_filter($s['inventory'] ?? [], fn ($i) => str_starts_with($i, 'costume-'))) >= 3,
            'vex' => in_array('F64', $frags, true),
        ];

        return array_values(array_keys(array_filter($rule)));
    }

    public function view(User $user, VortexSoul $soul): array
    {
        $found = $this->found($user, $soul);

        return [
            'dimensions' => collect(self::DIMENSIONS)->map(fn ($d, $id) => [
                'id' => $id,
                'name' => in_array($id, $found, true) ? $d[0] : '???',
                'found' => in_array($id, $found, true),
                'hint' => $d[1],
            ])->values()->all(),
            'unstable' => $this->unstable($soul),
            'zero' => (bool) ($soul->state['zero_path'] ?? false),
        ];
    }

    /** Time spent away; back home with (maybe) contraband. */
    public function returned(User $user, VortexSoul $soul, string $dim, int $seconds): array
    {
        if (! in_array($dim, [...$this->found($user, $soul), 'rare'], true)) {
            return ['ok' => false];
        }
        $s = $soul->state;
        $day = CarbonImmutable::now($s['tz'] ?? 'UTC')->toDateString();
        $away = ($s['dim_away']['day'] ?? null) === $day ? (int) $s['dim_away']['secs'] : 0;
        $s['dim_away'] = ['day' => $day, 'secs' => $away + max(0, min(3600, $seconds))];
        $contraband = null;
        $item = 'contraband-'.$dim;
        if ($seconds >= 60 && random_int(1, 5) === 1 && isset($this->econ->catalog()[$item]) && ! in_array($item, $s['inventory'] ?? [], true)) {
            $s['inventory'][] = $item;
            $contraband = $item;
        }
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'contraband' => $contraband, 'unstable' => $this->unstable($soul)];
    }

    /** J-18 · two hours away in a day and home starts leaking — until the next morning. */
    public function unstable(VortexSoul $soul): bool
    {
        $s = $soul->state ?? [];
        $local = CarbonImmutable::now($s['tz'] ?? 'UTC');

        return ($s['dim_away']['day'] ?? null) === $local->toDateString() && (int) ($s['dim_away']['secs'] ?? 0) >= 7200;
    }

    /**
     * J-20 · Dimension Zero: "SIDE A? Y/N". N opens the way to Side C early,
     * and he pays for it — corruption and the Rewinder right behind him.
     */
    public function zero(VortexSoul $soul, string $answer): array
    {
        $s = $soul->state;
        if (strtoupper(trim($answer)) !== 'N') {
            return ['ok' => true, 'side' => 'A'];
        }
        $s['zero_path'] = true;
        $s['fragments'] = array_values(array_unique([...($s['fragments'] ?? []), 'F63']));
        $s['corruption'] = max((float) ($s['corruption'] ?? 0), 80);
        $s['proximity'] = max((float) ($s['proximity'] ?? 0), 80);
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'side' => 'C'];
    }
}
