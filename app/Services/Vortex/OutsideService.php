<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexSoul;
use App\Mail\VoidReportMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * LADO Q · OUTSIDE THE APP, every channel opt-in with its own switch: the
 * weekly Void Report email (Q-01), the single "you vanished" email (Q-02),
 * a subscribable calendar (Q-04) and a weekly post to a Slack webhook in his
 * Polite voice (Q-09). No tracking, one-click unsubscribe.
 */
final class OutsideService
{
    public function __construct(private readonly SoulService $souls, private readonly FragmentService $fragments) {}

    public function settings(VortexSoul $soul): array
    {
        $o = $soul->state['outside'] ?? [];

        return [
            'email' => (bool) ($o['email'] ?? false),
            'calendar' => isset($o['calendar_token']) ? url('/api/mascot/calendar/'.$o['calendar_token'].'.ics') : null,
            'terminal' => $o['terminal_token'] ?? null,
            'slack' => isset($o['slack']) ? preg_replace('#(https://hooks\.slack\.com/services/)[^/]+/.*#', '$1…', $o['slack']) : null,
        ];
    }

    public function update(VortexSoul $soul, array $patch): array
    {
        $s = $soul->state;
        $o = $s['outside'] ?? [];
        if (array_key_exists('email', $patch)) {
            $o['email'] = (bool) $patch['email'];
        }
        if (array_key_exists('calendar', $patch)) {
            if ($patch['calendar']) {
                $o['calendar_token'] ??= Str::random(40);
            } else {
                unset($o['calendar_token']);
            }
        }
        if (array_key_exists('terminal', $patch)) {
            if ($patch['terminal']) {
                $o['terminal_token'] ??= Str::random(40);
            } else {
                unset($o['terminal_token']);
            }
        }
        if (array_key_exists('slack', $patch)) {
            $url = trim((string) $patch['slack']);
            if ($url === '') {
                unset($o['slack']);
            } elseif (preg_match('#^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$#', $url)) {
                $o['slack'] = $url;
            } else {
                return ['ok' => false, 'reason' => 'that isn\'t a slack incoming webhook. it has to start with https://hooks.slack.com/services/'];
            }
        }
        $s['outside'] = $o;
        $soul->state = $s;
        $soul->save();

        return ['ok' => true, 'settings' => $this->settings($soul)];
    }

    /** The week, in his voice (real cards, real numbers). Polite for shared channels. */
    public function week(User $user, VortexSoul $soul, bool $polite = false): array
    {
        $boards = Board::where('user_id', $user->id)->pluck('id')
            ->merge(DB::table('board_shares')->where('user_id', $user->id)->pluck('board_id'));
        $done = Card::whereIn('board_id', $boards)->where('done_at', '>=', now()->subWeek())->latest('done_at')->limit(6)->pluck('name');
        $doneCount = Card::whereIn('board_id', $boards)->where('done_at', '>=', now()->subWeek())->count();
        $late = Card::whereIn('board_id', $boards)->whereNull('archived_at')->whereNull('done_at')->where('due_date', '<', now())->count();
        $lines = [
            $polite ? "this week: {$doneCount} cards finished." : "{$doneCount} cards finished this week. ".($doneCount >= 10 ? 'suspicious. who are you trying to impress.' : 'a bold strategy: doing almost nothing, loudly.'),
        ];
        foreach ($done as $n) {
            $lines[] = '✓ '.mb_substr((string) $n, 0, 80);
        }
        $lines[] = $late > 0
            ? ($polite ? "{$late} cards are overdue." : "{$late} overdue. the metronome sends his regards.")
            : ($polite ? 'nothing is overdue.' : 'nothing overdue. i don\'t trust it.');
        $lore = $this->fragments->loreFor($soul);
        $ps = $polite ? null : ($lore !== [] ? $lore[array_rand($lore)] : 'the tape is a little shorter than last week. it always is.');

        return ['lines' => $lines, 'ps' => $ps];
    }

    /** Q-07 · the one number the share card brags about. */
    public function doneCount(User $user): int
    {
        $boards = Board::where('user_id', $user->id)->pluck('id')
            ->merge(DB::table('board_shares')->where('user_id', $user->id)->pluck('board_id'));

        return Card::whereIn('board_id', $boards)->whereNotNull('done_at')->count();
    }

    public function unsubscribeUrl(User $user): string
    {
        return URL::signedRoute('vortex.unsubscribe', ['user' => $user->id]);
    }

    /** Q-01 · the weekly report, to whoever opted in. */
    public function sendWeekly(User $user, VortexSoul $soul): bool
    {
        if (! ($soul->state['outside']['email'] ?? false) || ! $user->email) {
            return false;
        }
        $w = $this->week($user, $soul);
        Mail::to($user->email)->queue(new VoidReportMail(
            'the void report · week '.now()->format('W'),
            'THE VOID REPORT · WK '.now()->format('W'),
            $w['lines'], $w['ps'], $this->unsubscribeUrl($user),
        ));

        return true;
    }

    /** Q-02 · gone 14 days with email on: ONE note. Never a sequence. */
    public function absence(User $user, VortexSoul $soul): bool
    {
        $s = $soul->state;
        $last = $soul->last_seen_at;
        if (! ($s['outside']['email'] ?? false) || ! $last || $last->gt(now()->subDays(14))) {
            return false;
        }
        if (isset($s['outside']['absence_sent']) && CarbonImmutable::parse($s['outside']['absence_sent'])->gt($last)) {
            return false; // already sent for this absence
        }
        Mail::to($user->email)->queue(new VoidReportMail(
            'the cards are getting restless',
            'MISSING · LAST SEEN '.$last->format('d M'),
            ['the cards are getting restless. so am i.', '(not really.)', '(ok, a little.)'],
            null, $this->unsubscribeUrl($user),
        ));
        $s['outside']['absence_sent'] = now()->toIso8601String();
        $soul->state = $s;
        $soul->save();

        return true;
    }

    /** Q-09 · the weekly post to the user's Slack webhook (Polite). */
    public function slack(User $user, VortexSoul $soul): bool
    {
        $url = $soul->state['outside']['slack'] ?? null;
        if (! $url) {
            return false;
        }
        $w = $this->week($user, $soul, true);
        try {
            Http::timeout(8)->post($url, ['text' => "*the void report* (from Vortex, the ghost in Yondra)\n".implode("\n", $w['lines'])]);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** Q-04 · a subscribable calendar: the universe's dates and your deadlines, with comments. */
    public function ics(string $token): ?string
    {
        $soul = VortexSoul::where('state->outside->calendar_token', $token)->first();
        if (! $soul || ! ($user = User::find($soul->user_id))) {
            return null;
        }
        $events = [];
        // L-08 · the dated specials from the episode catalog, the next twelve months
        $from = now()->startOfDay()->toImmutable();
        $until = $from->addYear();
        foreach (config('vortex_episodes', []) as $ep) {
            if (! isset($ep['date'])) {
                continue;
            }
            foreach ($this->occurrences((string) $ep['date'], $from, $until) as $d) {
                $events[] = [$d, 'episode: '.$ep['title'], 'a special. it only airs today. he will be insufferable about it.'];
            }
        }
        // full moons ("tape moons") for the next six months
        $base = CarbonImmutable::parse('2000-01-06 18:14:00');
        for ($i = 0; $i < 7; $i++) {
            $n = (int) ceil(now()->diffInDays($base, true) / 29.530588853) + $i;
            $events[] = [$base->addSeconds((int) round($n * 29.530588853 * 86400)), 'tape moon', 'he gets strange tonight. stranger.'];
        }
        $boards = Board::where('user_id', $user->id)->pluck('id')
            ->merge(DB::table('board_shares')->where('user_id', $user->id)->pluck('board_id'));
        foreach (Card::whereIn('board_id', $boards)->whereNull('archived_at')->whereNull('done_at')->whereNotNull('due_date')
            ->where('due_date', '>=', now()->subDays(7))->limit(200)->get(['id', 'name', 'due_date']) as $c) {
            $events[] = [CarbonImmutable::parse($c->due_date), 'due: '.$c->name, 'good luck. you\'ll need it.'];
        }
        $esc = fn (string $t) => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $t);
        $out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Yondra//Vortex//EN', 'X-WR-CALNAME:the tape (vortex)', 'CALSCALE:GREGORIAN'];
        foreach ($events as $i => [$d, $title, $note]) {
            $out[] = 'BEGIN:VEVENT';
            $out[] = 'UID:vortex-'.md5($token.$i.$title.$d->toDateString()).'@yondra';
            $out[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
            $out[] = 'DTSTART;VALUE=DATE:'.$d->format('Ymd');
            $out[] = 'SUMMARY:'.$esc($title);
            $out[] = 'DESCRIPTION:'.$esc($note);
            $out[] = 'END:VEVENT';
        }
        // O-12 · the Void Hour airs a new show every week (the week starts Monday)
        $out[] = 'BEGIN:VEVENT';
        $out[] = 'UID:vortex-voidhour-'.md5($token).'@yondra';
        $out[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
        $out[] = 'DTSTART;VALUE=DATE:'.$from->startOfWeek()->format('Ymd');
        $out[] = 'RRULE:FREQ=WEEKLY;BYDAY=MO';
        $out[] = 'SUMMARY:the void hour (new show on 03.13 dead air)';
        $out[] = 'DESCRIPTION:'.$esc('your week, reviewed by a ghost with a microphone. tune in on the radio.');
        $out[] = 'END:VEVENT';
        $out[] = 'END:VCALENDAR';

        return implode("\r\n", $out)."\r\n";
    }

    /** Q-10 · what `npx yondra-vortex` sees: read-only, revocable, no session. */
    public function terminal(string $token): ?array
    {
        $soul = VortexSoul::where('state->outside->terminal_token', $token)->first();
        if (! $soul || ! ($user = User::find($soul->user_id))) {
            return null;
        }
        $v = $this->souls->view($soul, $user);
        $boards = Board::where('user_id', $user->id)->pluck('id')
            ->merge(DB::table('board_shares')->where('user_id', $user->id)->pluck('board_id'));
        $cards = Card::whereIn('board_id', $boards)->whereNull('archived_at')->whereNull('done_at')
            ->orderByRaw('due_date is null')->orderBy('due_date')->limit(12)->get(['name', 'due_date', 'priority'])
            ->map(fn ($c) => [
                'name' => mb_substr((string) $c->name, 0, 80),
                'due' => $c->due_date ? CarbonImmutable::parse($c->due_date)->toDateString() : null,
                'overdue' => $c->due_date && CarbonImmutable::parse($c->due_date)->isPast(),
                'priority' => $c->priority,
            ])->all();

        return [
            'name' => explode(' ', (string) $user->name)[0],
            'mood' => $v['mood'],
            'nickname' => $v['nickname'],
            'age_days' => $v['age_days'],
            'deaths' => $v['deaths'],
            'corruption' => $v['corruption'],
            'done_week' => Card::whereIn('board_id', $boards)->where('done_at', '>=', now()->subWeek())->count(),
            'cards' => $cards,
        ];
    }

    /** @return list<CarbonImmutable> the days a dated special airs in [from, until) */
    private function occurrences(string $rule, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $days = [];
        if ($rule === 'fri13') {
            for ($m = $from->startOfMonth(); $m->lt($until); $m = $m->addMonth()) {
                $d = $m->setDay(13);
                if ($d->isFriday() && $d->gte($from)) {
                    $days[] = $d;
                }
            }

            return $days;
        }
        $mmdd = $rule === 'equinox' ? ['03-20', '09-22'] : [$rule];
        foreach ([(int) $from->format('Y'), (int) $from->format('Y') + 1] as $y) {
            foreach ($mmdd as $md) {
                $d = CarbonImmutable::parse("{$y}-{$md}");
                if ($d->gte($from) && $d->lt($until)) {
                    $days[] = $d;
                }
            }
        }

        return $days;
    }
}
