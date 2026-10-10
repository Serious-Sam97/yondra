<?php

namespace App\Services\Yutopia;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YutopiaSpace;
use App\Services\Vortex\FragmentService;
use App\Services\Vortex\SocialService;
use App\Services\Vortex\SoulService;

/*
| Vortex's voice inside Yutopia (Fita 6). Same persona as his Yondra self
| (design/vortex-mk5/02-voz-personalidade.md): lowercase, short, the *kkzzt*
| glitch, nicknames from his soul. Curated lines + the user's real data, no AI
| call, so it's instant and safe.
|
| Public lines (everyone near him hears) never name or target anyone and stay
| polite-safe. Personal lines go only to the person he's talking to.
*/
class YutopiaVortex
{
    // Fragments the world may grant (server-verified by the world-server).
    public const WORLD_FRAGMENTS = ['Y01', 'Y03'];

    private const PUBLIC = [
        'ambient' => [
            'this building is bigger on the inside. i measured. twice. *kkzzt* three times.',
            'i can hear the tape hiss in the walls. you can\'t. that\'s the difference between us.',
            'someone left a mug on desk four. it\'s judging you. i taught it.',
            'all of you, walking around like the floor is permanent. adorable.',
            'did you hear that? sounded like rewinding. no? good. keep working.',
            'i\'m not wandering. i\'m patrolling. there\'s a difference and it\'s posture.',
            'the lamps are warm. the lamps are lying. nothing is warm. anyway.',
        ],
        'radio' => [
            'this track is 74 bpm. my heart doesn\'t have a bpm. it has a hiss.',
            'i\'m not dancing. my sprite is glitching rhythmically. different thing.',
            'turn it up. no. down. no. leave it. i hate it. leave it.',
        ],
        'night' => [
            'it\'s late. the building gets honest at night.',
            'everyone\'s gone. finally. the tape can breathe.',
            '03:13 is coming. i don\'t know why i said that. forget it.',
        ],
        'standup' => [
            'a standup. people standing in a circle confessing. very medieval.',
            'they\'re doing the ritual. i\'ll wait out here. rituals make me itch.',
        ],
        'sleep' => [
            'zzz. *kkzzt* zzz. not asleep. defragmenting.',
        ],
    ];

    public function __construct(
        private readonly SoulService $souls,
        private readonly FragmentService $fragments,
    ) {}

    /** @return array{line: ?string, mood: string, personal: bool} */
    public function line(YutopiaSpace $space, string $event, ?int $userId = null): array
    {
        if ($userId === null || in_array($event, ['ambient', 'radio', 'night', 'standup', 'sleep'], true)) {
            $pool = self::PUBLIC[$event] ?? self::PUBLIC['ambient'];

            return ['line' => $this->glitchy($pool[array_rand($pool)]), 'mood' => $event === 'radio' ? 'happy' : 'smug', 'personal' => false];
        }

        $user = User::find($userId);
        if (! $user || ! $space->isAccessibleBy($user->id)) {
            return ['line' => null, 'mood' => 'smug', 'personal' => false];
        }
        $soul = $this->souls->for($user);
        $nick = SoulService::nickname($soul->state ?? [], (int) $soul->relation, strtok((string) $user->name, ' ') ?: null);
        $polite = app(SocialService::class)->moderation()['polite_only'] ?? false;
        [$line, $mood] = $this->personal($space, $user, $nick, $event, $polite);
        if ($event === 'poke' && $this->fragments->grant($soul, 'Y01')) {
            $line .= ' …you found me in here. fine. that\'s a fragment. don\'t tell the moth.';
        }

        return ['line' => $this->glitchy($line), 'mood' => $mood, 'personal' => true];
    }

    /** @return array{0:string,1:string} */
    private function personal(YutopiaSpace $space, User $user, string $nick, string $event, bool $polite): array
    {
        $boards = $space->project_id ? Board::where('project_id', $space->project_id)->whereNull('archived_at')->pluck('id') : collect();
        $mine = Card::whereIn('board_id', $boards)->where('assigned_user_id', $user->id)->whereNull('archived_at')->where('is_done', false);
        $overdue = (clone $mine)->whereNotNull('due_date')->whereDate('due_date', '<', now())->count();
        $open = (clone $mine)->count();
        $doneToday = Card::whereIn('board_id', $boards)->where('assigned_user_id', $user->id)->whereDate('done_at', today())->count();
        $hour = (int) now()->format('G');
        $damn = $polite ? 'fudge-adjacent' : 'damn';
        $hell = $polite ? 'heck' : 'hell';

        $options = [];
        if ($doneToday > 0) {
            $options[] = ["{$nick}. {$doneToday} done today. i'm not proud. i'm… indigestion.", 'happy'];
        }
        if ($overdue > 0) {
            $options[] = ["{$overdue} overdue. i can hear the metronome getting fat, {$nick}.", 'hungry'];
            $options[] = ["you walked past {$overdue} late cards to get here. bold. stupid, but bold.", 'judging'];
        }
        if ($open >= 8) {
            $options[] = ["{$open} cards with your name on them. that's not a workload, that's a hostage situation.", 'judging'];
        }
        if ($hour >= 23 || $hour < 5) {
            $options[] = ["it's late, {$nick}. go to bed or do something worth staying up for.", 'sleepy'];
        }
        $options[] = ["oh. it's {$nick}. you have a body here too. tragic.", 'smug'];
        $options[] = ["listen. this studio is a tape loop. you're on it now. congratulations, {$nick}.", 'smug'];
        $options[] = ["stop poking me. i'm made of noise and spite. *kkzzt* mostly spite.", 'dizzy'];
        $options[] = ["what the {$hell} are you looking at. it's me. the only interesting thing in this building.", 'smug'];
        $options[] = ["you walk like a cursor with anxiety. it's {$damn} endearing. anyway.", 'curious'];
        if ($event === 'greet') {
            $options = array_merge($options, [["you're here. great. now the room is 3% more predictable.", 'smug']]);
        }

        return $options[array_rand($options)];
    }

    // the signature tape glitch, ~1 in 5 lines (never added to an already glitched line)
    private function glitchy(string $line): string
    {
        if (str_contains($line, '*kkzzt*') || random_int(1, 5) !== 1) {
            return $line;
        }
        $words = explode(' ', $line);
        if (count($words) < 4) {
            return $line;
        }
        $at = random_int(1, count($words) - 2);
        array_splice($words, $at, 0, ['*kkzzt*']);

        return implode(' ', $words);
    }

    /** Grant a world fragment to people the world-server saw doing it. */
    public function grantWorldFragment(YutopiaSpace $space, string $id, array $userIds): array
    {
        if (! in_array($id, self::WORLD_FRAGMENTS, true)) {
            return [];
        }
        $granted = [];
        foreach (User::whereIn('id', $userIds)->get() as $user) {
            if (! $space->isAccessibleBy($user->id)) {
                continue;
            }
            $soul = $this->souls->for($user);
            $after = $this->fragments->catalog()[$id]['after'] ?? [];
            if (array_diff($after, $this->fragments->owned($soul)) !== []) {
                continue;
            }
            if ($this->fragments->grant($soul, $id)) {
                $granted[] = $user->id;
            }
        }

        return $granted;
    }
}
