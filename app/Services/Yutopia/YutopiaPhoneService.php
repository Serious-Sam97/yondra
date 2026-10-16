<?php

namespace App\Services\Yutopia;

use App\Events\YutopiaPhoneEvent;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YutopiaAvatar;
use App\Infrastructure\Models\YutopiaMessage;
use App\Infrastructure\Models\YutopiaPhone;
use App\Infrastructure\Models\YutopiaSpace;
use App\Infrastructure\Models\YutopiaThread;
use App\Infrastructure\Models\YutopiaThreadMember;
use App\Jobs\NotifyYutopiaText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
| Yutopia's phone, Yondra's half (yutopia/docs/plan/21-cellphone.md): the
| settings someone keeps, the team as contacts (with where they are right now),
| threads and texts, and the live pushes. Anyone you share a project with is on
| your team; a space's own thread holds everyone who can open that space.
| Vortex is a contact too (his thread is yours alone; his lines are curated,
| in YutopiaVortex).
*/
class YutopiaPhoneService
{
    public const DEFAULTS = [
        'case' => 'walnut', 'wallpaper' => 'portal', 'lockWallpaper' => 'portal', 'ringtone' => 'citypop',
        'textTone' => 'pop', 'tones' => [], 'theme' => 'dark', 'textSize' => 'normal', 'vibrate' => true,
        'favourites' => [], 'installed' => [],
    ];

    private const CASES = ['walnut', 'cream', 'rust', 'olive', 'graphite', 'brass', 'vortex'];

    private const RINGTONES = ['citypop', 'vortex', 'rewind', 'hifi', 'synthbass', 'arcade', 'bell', 'rotary', 'lofi', 'silent'];

    private const TEXT_TONES = ['pop', 'chime', 'tape', 'blip', 'glass', 'silent'];

    private const WALLPAPERS = ['portal', 'grid', 'golden', 'campus', 'vortex', 'tape', 'aurora', 'night', 'vhs', 'orb', 'dunes', 'static'];

    public function __construct(
        private readonly YutopiaSpaces $spaces,
        private readonly YutopiaPresence $presence,
    ) {}

    // ── settings ────────────────────────────────────────────────────────────
    public function settings(User $user): array
    {
        $row = YutopiaPhone::where('user_id', $user->id)->first();

        return $this->clean($row?->settings ?? []);
    }

    public function saveSettings(User $user, array $in): array
    {
        $settings = $this->clean($in);
        YutopiaPhone::updateOrCreate(['user_id' => $user->id], ['settings' => $settings]);

        return $settings;
    }

    // the same rules as shared/phone.ts parsePhoneSettings
    public function clean(array $in): array
    {
        $d = self::DEFAULTS;
        $wp = fn ($v) => is_string($v) && (in_array($v, self::WALLPAPERS, true) || preg_match('/^photo:\d{1,12}$/', $v));
        $tones = [];
        foreach (array_slice((array) ($in['tones'] ?? []), 0, 200, true) as $uid => $tone) {
            if (preg_match('/^\d{1,10}$/', (string) $uid) && in_array($tone, self::RINGTONES, true)) {
                $tones[(string) $uid] = $tone;
            }
        }
        $ids = fn ($v, $max) => array_slice(array_values(array_unique(array_map('intval', array_filter((array) $v, fn ($n) => is_int($n) && $n >= 0)))), 0, $max);

        return [
            'case' => in_array($in['case'] ?? null, self::CASES, true) ? $in['case'] : $d['case'],
            'wallpaper' => $wp($in['wallpaper'] ?? null) ? $in['wallpaper'] : $d['wallpaper'],
            'lockWallpaper' => $wp($in['lockWallpaper'] ?? null) ? $in['lockWallpaper'] : $d['lockWallpaper'],
            'ringtone' => in_array($in['ringtone'] ?? null, self::RINGTONES, true) ? $in['ringtone'] : $d['ringtone'],
            'textTone' => in_array($in['textTone'] ?? null, self::TEXT_TONES, true) ? $in['textTone'] : $d['textTone'],
            'tones' => (object) $tones,
            'theme' => ($in['theme'] ?? null) === 'light' ? 'light' : 'dark',
            'textSize' => in_array($in['textSize'] ?? null, ['small', 'large'], true) ? $in['textSize'] : 'normal',
            'vibrate' => is_bool($in['vibrate'] ?? null) ? $in['vibrate'] : $d['vibrate'],
            'favourites' => $ids($in['favourites'] ?? [], 12),
            'installed' => array_slice(array_values(array_filter((array) ($in['installed'] ?? []), fn ($s) => is_string($s) && preg_match('/^[a-z0-9-]{1,32}$/', $s))), 0, 40),
        ];
    }

    // ── the team ────────────────────────────────────────────────────────────
    /** Everyone the user shares a project with (not themself). @return Collection<int, User> */
    public function team(User $user): Collection
    {
        $projects = Project::query()->whereNull('archived_at')
            ->where(fn ($q) => $q->where('owner_id', $user->id)->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id)))
            ->with(['owner:id,name', 'members:id,name'])->get();

        return $projects->flatMap(fn (Project $p) => collect([$p->owner])->merge($p->members))
            ->filter()->unique('id')->reject(fn (User $u) => $u->id === $user->id)->values();
    }

    public function onTeam(User $user, int $otherId): bool
    {
        return $this->team($user)->contains('id', $otherId);
    }

    /** Contacts with where they are right now (every space's presence). */
    public function contacts(User $user, ?int $hereSpaceId): array
    {
        $team = $this->team($user);
        $looks = YutopiaAvatar::whereIn('user_id', $team->pluck('id'))->pluck('layers', 'user_id');
        $where = [];
        foreach ($this->spaces->forUser($user) as $space) {
            foreach ($this->presence->get($space)['people'] as $p) {
                // the space someone's in that I can see (the first one wins)
                $where[$p['userId']] ??= ['spaceId' => $space->id, 'space' => $space->name, 'area' => $p['areaName'] ?? null, 'status' => $p['status'] ?? 'online'];
            }
        }

        return $team->map(fn (User $u) => [
            'uid' => $u->id,
            'name' => $u->name,
            'avatar' => json_encode(array_merge(YutopiaSpaces::DEFAULT_AVATAR, $looks[$u->id] ?? [])),
            'online' => isset($where[$u->id]),
            'spaceId' => $where[$u->id]['spaceId'] ?? null,
            'space' => $where[$u->id]['space'] ?? null,
            'area' => $where[$u->id]['area'] ?? null,
            'status' => $where[$u->id]['status'] ?? 'offline',
        ])->sortBy([['online', 'desc'], ['name', 'asc']])->values()->all();
    }

    // ── threads ─────────────────────────────────────────────────────────────
    public function spaceThread(YutopiaSpace $space): YutopiaThread
    {
        return YutopiaThread::firstOrCreate(['kind' => 'space', 'space_id' => $space->id], ['name' => $space->name]);
    }

    public function direct(User $a, int $b): YutopiaThread
    {
        $key = min($a->id, $b).':'.max($a->id, $b);

        return DB::transaction(function () use ($a, $b, $key) {
            $t = YutopiaThread::firstOrCreate(['direct_key' => $key], ['kind' => 'direct', 'created_by' => $a->id]);
            foreach ([$a->id, $b] as $uid) {
                YutopiaThreadMember::firstOrCreate(['thread_id' => $t->id, 'user_id' => $uid]);
            }

            return $t;
        });
    }

    public function vortex(User $user): YutopiaThread
    {
        $t = YutopiaThread::firstOrCreate(['direct_key' => 'vortex:'.$user->id], ['kind' => 'vortex', 'created_by' => $user->id]);
        YutopiaThreadMember::firstOrCreate(['thread_id' => $t->id, 'user_id' => $user->id]);

        return $t;
    }

    /** A group with these people (a call between them reuses the same one). */
    public function group(User $creator, array $uids, ?string $name, bool $byKey = false): YutopiaThread
    {
        $all = collect($uids)->push($creator->id)->map(fn ($u) => (int) $u)->unique()->sort()->values();
        $attrs = ['kind' => 'group', 'name' => $name ? mb_substr($name, 0, 60) : null, 'created_by' => $creator->id];
        $t = $byKey
            ? YutopiaThread::firstOrCreate(['direct_key' => 'g:'.substr(md5($all->implode(',')), 0, 24)], $attrs)
            : YutopiaThread::create($attrs);
        foreach ($all as $uid) {
            YutopiaThreadMember::firstOrCreate(['thread_id' => $t->id, 'user_id' => $uid]);
        }

        return $t;
    }

    /** Who is in a thread (user ids): its members, or for a space thread everyone who can open the space. @return int[] */
    public function people(YutopiaThread $t): array
    {
        if ($t->kind === 'space') {
            $project = $t->space?->project;

            return $project ? collect([$project->owner_id])->merge($project->members()->pluck('users.id'))->map(fn ($u) => (int) $u)->unique()->values()->all() : [];
        }

        return $t->members()->whereNull('left_at')->pluck('user_id')->map(fn ($u) => (int) $u)->all();
    }

    public function canSee(YutopiaThread $t, int $uid): bool
    {
        if ($t->kind === 'space') {
            return (bool) $t->space?->isAccessibleBy($uid);
        }

        return $t->members()->where('user_id', $uid)->whereNull('left_at')->exists();
    }

    public function member(YutopiaThread $t, int $uid): YutopiaThreadMember
    {
        // joining a space thread late: what came before counts as read
        return YutopiaThreadMember::firstOrCreate(
            ['thread_id' => $t->id, 'user_id' => $uid],
            ['last_read_id' => (int) $t->messages()->max('id')],
        );
    }

    /** The threads someone sees: theirs, and the current space's own. */
    public function threads(User $user, ?YutopiaSpace $here): array
    {
        if ($here && $here->isAccessibleBy($user->id)) {
            $this->member($this->spaceThread($here), $user->id);
        }
        $ids = YutopiaThreadMember::where('user_id', $user->id)->whereNull('left_at')->pluck('thread_id');
        $threads = YutopiaThread::whereIn('id', $ids)->with('space.project')->orderByDesc('last_message_at')->limit(100)->get()
            ->filter(fn (YutopiaThread $t) => $t->kind !== 'space' || $t->space?->isAccessibleBy($user->id));

        return $threads->map(fn (YutopiaThread $t) => $this->shapeThread($t, $user->id))->values()->all();
    }

    public function shapeThread(YutopiaThread $t, int $uid): array
    {
        $member = YutopiaThreadMember::where('thread_id', $t->id)->where('user_id', $uid)->first();
        $readId = (int) ($member?->last_read_id ?? 0);
        $last = $t->messages()->with('photo')->latest('id')->first();
        $unread = $t->messages()->where('id', '>', $readId)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $uid))->count();
        $members = $t->kind === 'space' ? [] : $t->members()->whereNull('left_at')->where('user_id', '!=', $uid)->pluck('user_id')->map(fn ($u) => (int) $u)->all();
        $read = $t->kind === 'space' ? [] : $t->members()->pluck('last_read_id', 'user_id')->map(fn ($v) => (int) $v)->all();
        $name = match ($t->kind) {
            'space' => $t->space?->name ?? $t->name ?? 'Space',
            'group' => $t->name ?: User::whereIn('id', array_slice($members, 0, 3))->pluck('name')->map(fn ($n) => strtok((string) $n, ' '))->implode(', '),
            'vortex' => 'Vortex',
            default => '',
        };

        return [
            'id' => $t->id,
            'kind' => $t->kind,
            'name' => (string) $name,
            'members' => $members,
            'space_id' => $t->space_id,
            'unread' => min(99, $unread),
            'last' => $last ? $this->shapeMessage($last) : null,
            'muted' => (bool) ($member?->muted ?? false),
            'read' => (object) $read,
        ];
    }

    public function shapeMessage(YutopiaMessage $m): array
    {
        return [
            'id' => $m->id,
            'thread_id' => $m->thread_id,
            'user_id' => $m->user_id,
            'kind' => $m->kind,
            'body' => $m->body,
            'photo' => $m->photo?->toPhone(),
            'meta' => $m->meta,
            'reactions' => (object) ($m->reactions ?? []),
            'reply_to' => $m->reply_to,
            'at' => $m->created_at->getTimestampMs(),
        ];
    }

    // ── texts ───────────────────────────────────────────────────────────────
    /** Write a message into a thread and push it to everyone in it (not the sender). */
    public function post(YutopiaThread $t, ?int $from, array $data): YutopiaMessage
    {
        $m = YutopiaMessage::create([
            'thread_id' => $t->id,
            'user_id' => $from,
            'kind' => $data['kind'] ?? 'text',
            'body' => $data['body'] ?? null,
            'photo_id' => $data['photo_id'] ?? null,
            'meta' => $data['meta'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
        ]);
        $t->forceFill(['last_message_at' => now()])->save();
        if ($from) {
            YutopiaThreadMember::where('thread_id', $t->id)->where('user_id', $from)->update(['last_read_id' => $m->id]);
        }
        $m->load('photo');
        $shaped = $this->shapeMessage($m);
        foreach ($this->people($t) as $uid) {
            if ($uid === $from && ($data['kind'] ?? 'text') !== 'call') {
                continue;
            }
            $this->push($uid, ['type' => 'message', 'message' => $shaped, 'thread' => $this->shapeThread($t, $uid)]);
        }
        // nobody reads it in 10 minutes: a Yondra notification (their preferences decide)
        if ($from && in_array($t->kind, ['direct', 'group'], true) && ($data['kind'] ?? 'text') !== 'call') {
            NotifyYutopiaText::dispatch($m->id)->delay(now()->addMinutes(10));
        }

        return $m;
    }

    public function push(int $uid, array $payload): void
    {
        try {
            broadcast(new YutopiaPhoneEvent($uid, $payload));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function pushAll(YutopiaThread $t, array $payload, ?int $except = null): void
    {
        foreach ($this->people($t) as $uid) {
            if ($uid !== $except) {
                $this->push($uid, $payload);
            }
        }
    }
}
