<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexJournal;
use App\Infrastructure\Models\VortexMemory;
use App\Jobs\WriteVortexLetterJob;
use App\Services\Ai\AiDriver;
use App\Services\Vortex\AchievementService;
use App\Services\Vortex\AgentService;
use App\Services\Vortex\AiBudget;
use App\Services\Vortex\ArcadeService;
use App\Services\Vortex\BelowService;
use App\Services\Vortex\CreatorService;
use App\Services\Vortex\DimensionService;
use App\Services\Vortex\EconomyService;
use App\Services\Vortex\EndingService;
use App\Services\Vortex\FragmentService;
use App\Services\Vortex\GreatRewindService;
use App\Services\Vortex\JournalService;
use App\Services\Vortex\LabService;
use App\Services\Vortex\OutsideService;
use App\Services\Vortex\PrivacyService;
use App\Services\Vortex\PushService;
use App\Services\Vortex\RadioService;
use App\Services\Vortex\SimulatorService;
use App\Services\Vortex\SocialService;
use App\Services\Vortex\SoulService;
use App\Services\Vortex\StoryService;
use App\Services\Vortex\TradeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The mascot's own endpoints (Vortex MK-V). Prefix /api/mascot — /api/vortex is
 * the admin error monitor, a different thing that shares the name.
 */
class MascotController extends Controller
{
    /**
     * Resolve the chips Vortex writes in chat ({{card:ID}}, {{board:ID}},
     * {{project:ID}}) into something clickable. Anything the caller can't see
     * resolves to null — never a leak, just a broken chip.
     */
    public function resolve(Request $request)
    {
        $data = $request->validate([
            'refs' => ['required', 'array', 'max:20'],
            'refs.*.type' => ['required', 'string', 'in:card,board,project'],
            'refs.*.id' => ['required', 'integer', 'min:1'],
        ]);
        $userId = (int) Auth::id();

        $out = [];
        foreach ($data['refs'] as $ref) {
            $id = (int) $ref['id'];
            $key = $ref['type'].':'.$id;
            $out[$key] = match ($ref['type']) {
                'card' => (function () use ($id, $userId) {
                    $card = Card::whereNull('archived_at')->find($id);
                    $board = $card ? Board::find($card->board_id) : null;

                    return $card && $board && $board->archived_at === null && $board->isAccessibleBy($userId)
                        ? ['name' => $card->name, 'board_id' => $board->id, 'key' => Card::ticketKey($board->ticket_prefix, $card->ticket_number)]
                        : null;
                })(),
                'board' => (function () use ($id, $userId) {
                    $board = Board::find($id);

                    return $board && $board->archived_at === null && $board->isAccessibleBy($userId)
                        ? ['name' => $board->name, 'board_id' => $board->id]
                        : null;
                })(),
                'project' => (function () use ($id, $userId) {
                    $project = Project::find($id);

                    return $project && $project->archived_at === null && $project->isAccessibleBy($userId)
                        ? ['name' => $project->name, 'project_id' => $project->id]
                        : null;
                })(),
            };
        }

        return response()->json(['refs' => $out]);
    }

    /** C-07 · his state, as the client sees it. */
    public function soul(Request $request, SoulService $souls, EndingService $endings)
    {
        $user = $request->user();
        $soul = $souls->for($user);

        return response()->json([
            ...$souls->view($soul, $user),
            'help' => $endings->helpFrame($soul),
            // M-20 · this week's team champion wears a crown
            'champion' => (bool) (app(ArcadeService::class)->champion($user)['you'] ?? false),
        ]);
    }

    /** T-04 · forget everything (the password guards it, like deleting the account does). */
    public function forgetEverything(Request $request, PrivacyService $privacy)
    {
        $d = $request->validate([
            'password' => ['required', 'string'],
            'keep_achievements' => ['sometimes', 'boolean'],
        ]);
        abort_unless(Hash::check($d['password'], (string) $request->user()->password), 422, 'Wrong password.');

        return response()->json($privacy->forget($request->user(), (bool) ($d['keep_achievements'] ?? false)));
    }

    /**
     * T-07 · consented, aggregate-only telemetry: per event how often it was
     * shown, closed fast (irritation) or clicked (fun). No user id is stored.
     */
    public function telemetry(Request $request)
    {
        $d = $request->validate([
            'counts' => ['required', 'array', 'max:60'],
            'counts.*.shown' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'counts.*.fast' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'counts.*.clicked' => ['sometimes', 'integer', 'min:0', 'max:500'],
        ]);
        // one batch per user per day; the rest is dropped
        if (! Cache::add('vxtel:sent:'.$request->user()->id.':'.now()->toDateString(), 1, now()->addDay())) {
            return response()->json(['ok' => true, 'dropped' => true]);
        }
        $day = now()->toDateString();
        DB::transaction(function () use ($d, $day) {
            foreach ($d['counts'] as $event => $c) {
                if (preg_match('/^[a-z0-9:._-]{2,40}$/', (string) $event) !== 1) {
                    continue;
                }
                DB::table('vortex_telemetry')->insertOrIgnore(['day' => $day, 'event' => $event]);
                DB::table('vortex_telemetry')->where('day', $day)->where('event', $event)->update([
                    'shown' => DB::raw('shown + '.(int) ($c['shown'] ?? 0)),
                    'fast' => DB::raw('fast + '.(int) ($c['fast'] ?? 0)),
                    'clicked' => DB::raw('clicked + '.(int) ($c['clicked'] ?? 0)),
                ]);
            }
        });
        // retention: aggregates older than the configured window go
        DB::table('vortex_telemetry')
            ->where('day', '<', now()->subDays((int) config('vortex_mk5.telemetry.retention_days', 90))->toDateString())
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** T-07 · the aggregates, for calibrating the Director (dev only). */
    public function devTelemetry(Request $request)
    {
        abort_unless(app()->isLocal() || config('services.vortex.dev'), 404);
        $days = [];
        $rows = DB::table('vortex_telemetry')->where('day', '>=', now()->subDays(13)->toDateString())->orderBy('event')->get();
        for ($i = 0; $i < 14; $i++) {
            $day = now()->subDays($i)->toDateString();
            $days[$day] = $rows->filter(fn ($r) => substr((string) $r->day, 0, 10) === $day)
                ->mapWithKeys(fn ($r) => [$r->event => ['shown' => (int) $r->shown, 'fast' => (int) $r->fast, 'clicked' => (int) $r->clicked]])
                ->all();
        }

        return response()->json(['days' => $days, 'ai' => AiBudget::today($request->user()->id)]);
    }

    /**
     * T-12 · MK-IV progress (kept on the device) moves to his soul without
     * losses: achievements are a union, counters keep the max, idempotent.
     * GET hands it back so a new device starts where the old one was.
     */
    public function mk4(Request $request, SoulService $souls)
    {
        $soul = $souls->for($request->user());
        if ($request->isMethod('post')) {
            $ach = ['night-owl', 'archivist', 'jam-breaker', 'exorcist', 'escapee', 'ghost-buster', 'riddle-master', 'sweet', 'seeker'];
            $cos = ['none', 'witch', 'scarf', 'party', 'monocle', 'crown', 'sunglasses'];
            $d = $request->validate([
                'achievements' => ['sometimes', 'array', 'max:20'],
                'achievements.*' => ['string', 'in:'.implode(',', $ach)],
                'costumes' => ['sometimes', 'array', 'max:20'],
                'costumes.*' => ['string', 'in:'.implode(',', $cos)],
                'streak' => ['sometimes', 'integer', 'min:0', 'max:3650'],
                'compliments' => ['sometimes', 'integer', 'min:0', 'max:100000'],
                'escape' => ['sometimes', 'integer', 'min:0', 'max:4'],
                'jams' => ['sometimes', 'integer', 'min:0', 'max:100000'],
                'birthday' => ['sometimes', 'nullable', 'string', 'regex:/^\d{2}-\d{2}$/'],
            ]);
            $s = $soul->state;
            $m = $s['mk4'] ?? [];
            $m['achievements'] = array_values(array_unique([...($m['achievements'] ?? []), ...($d['achievements'] ?? [])]));
            $m['costumes'] = array_values(array_unique([...($m['costumes'] ?? []), ...($d['costumes'] ?? [])]));
            foreach (['streak', 'compliments', 'escape', 'jams'] as $k) {
                $m[$k] = max((int) ($m[$k] ?? 0), (int) ($d[$k] ?? 0));
            }
            if (! empty($d['birthday'])) {
                $m['birthday'] = $d['birthday'];
            }
            $m['migrated_at'] ??= now()->toIso8601String();
            $s['mk4'] = $m;
            $soul->state = $s;
            $soul->save();
        }

        return response()->json($soul->state['mk4'] ?? null);
    }

    /** Q-03 · real push: the public key, this person's state, and the 03:13 switch. */
    public function push(Request $request, SoulService $souls, PushService $push)
    {
        $soul = $souls->for($request->user());

        return response()->json([
            'enabled' => PushService::enabled(),
            'public_key' => PushService::enabled() ? (string) config('vortex_mk5.push.public_key') : null,
            'subscribed' => $push->subscribed($request->user()),
            'night' => (bool) ($soul->state['outside']['push_night'] ?? false),
        ]);
    }

    public function pushSubscribe(Request $request, SoulService $souls, PushService $push)
    {
        $d = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'url', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
            'night' => ['sometimes', 'boolean'],
        ]);
        abort_unless(PushService::enabled(), 404);
        $push->subscribe($request->user(), $d['endpoint'], $d['keys']['p256dh'], $d['keys']['auth']);
        if (array_key_exists('night', $d)) {
            $soul = $souls->for($request->user());
            $s = $soul->state;
            $s['outside']['push_night'] = (bool) $d['night'];
            $soul->state = $s;
            $soul->save();
        }

        return response()->json(['ok' => true, 'subscribed' => $push->subscribed($request->user())]);
    }

    public function pushUnsubscribe(Request $request, PushService $push)
    {
        $d = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $push->unsubscribe($request->user(), $d['endpoint']);

        return response()->json(['ok' => true, 'subscribed' => $push->subscribed($request->user())]);
    }

    /** T-11 · which sides of MK-V are switched on (the client mounts only these). */
    public function flags()
    {
        return response()->json(['lados' => config('vortex_mk5.lados', [])]);
    }

    /** S · the creator tools: tricks, taught lines, taste, your costume. */
    public function creator(Request $request, SoulService $souls)
    {
        $soul = $souls->for($request->user());

        return response()->json([
            'tricks' => $soul->state['tricks'] ?? [],
            'lines' => $soul->state['taught'] ?? [],
            'taste' => $soul->state['taste'] ?? (object) [],
            'costume' => $soul->state['custom_costume'] ?? null,
            'triggers' => CreatorService::TRIGGERS,
            'hats' => CreatorService::HATS,
            'accessories' => CreatorService::ACCESSORIES,
        ]);
    }

    public function creatorTrick(Request $request, SoulService $souls, CreatorService $creator)
    {
        $soul = $souls->for($request->user());
        if ($request->isMethod('delete')) {
            return response()->json(['tricks' => $creator->dropTrick($soul, (string) $request->route('id'))]);
        }
        $d = $request->validate([
            'trigger' => ['required', 'string', 'max:20'],
            'anim' => ['required', 'string', 'max:32'],
            'line' => ['nullable', 'string', 'max:200'],
        ]);
        $r = $creator->addTrick($soul, $d['trigger'], $d['anim'], (string) ($d['line'] ?? ''));

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function creatorLine(Request $request, SoulService $souls, CreatorService $creator)
    {
        $soul = $souls->for($request->user());
        if ($request->isMethod('delete')) {
            return response()->json(['lines' => $creator->forget($soul, (int) $request->route('index'))]);
        }
        $d = $request->validate(['text' => ['required', 'string', 'max:300']]);
        $r = $creator->teach($soul, $d['text']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function creatorFeedback(Request $request, SoulService $souls, CreatorService $creator)
    {
        $d = $request->validate([
            'style' => ['required', 'string', 'max:20'],
            'vote' => ['required', 'integer', 'in:-1,1'],
        ]);

        return response()->json($creator->feedback($souls->for($request->user()), $d['style'], (int) $d['vote']));
    }

    public function creatorCostume(Request $request, SoulService $souls, CreatorService $creator)
    {
        $d = $request->validate([
            'hat' => ['required', 'string'],
            'acc' => ['required', 'string'],
            'c1' => ['required', 'string'],
            'c2' => ['required', 'string'],
        ]);
        $r = $creator->costume($souls->for($request->user()), $d);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** S-04 · the content editor reads the live catalogs (dev only; read-only). */
    public function devContent()
    {
        abort_unless(app()->isLocal() || config('services.vortex.dev'), 404);
        $npcs = [];
        foreach (glob(resource_path('prompts/vortex/npcs/*.md')) ?: [] as $f) {
            $npcs[basename($f, '.md')] = (string) file_get_contents($f);
        }

        return response()->json([
            'episodes' => config('vortex_episodes', []),
            // the claim secrets stay on the server even here
            'fragments' => array_map(fn ($f) => array_diff_key($f, ['secret' => 1]), config('vortex_fragments', [])),
            'items' => config('vortex_items', []),
            'npcs' => $npcs,
        ]);
    }

    /** S-06 · run the soul simulator (dev only; always rolled back). */
    public function devSimulate(Request $request, SimulatorService $sim)
    {
        abort_unless(app()->isLocal() || config('services.vortex.dev'), 404);
        $d = $request->validate([
            'profile' => ['required', 'string', 'in:'.implode(',', array_keys(SimulatorService::PROFILES))],
            'days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        return response()->json(['profile' => $d['profile'], 'days' => $sim->run($d['profile'], (int) $d['days'])]);
    }

    /** Q · his channels outside the app (each opt-in). */
    public function outside(Request $request, SoulService $souls, OutsideService $outside)
    {
        $soul = $souls->for($request->user());
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'email' => ['sometimes', 'boolean'],
                'calendar' => ['sometimes', 'boolean'],
                'terminal' => ['sometimes', 'boolean'],
                'slack' => ['sometimes', 'nullable', 'string', 'max:300'],
            ]);
            $r = $outside->update($soul, $data);

            return response()->json($r, $r['ok'] ? 200 : 422);
        }

        return response()->json([...$outside->settings($soul), 'done' => $outside->doneCount($request->user())]);
    }

    /** Q-04 · the calendar feed (token in the URL; no session). */
    public function calendar(string $token, OutsideService $outside)
    {
        $ics = $outside->ics($token);
        abort_if($ics === null, 404);

        return response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'inline; filename="vortex.ics"']);
    }

    /** Q-10 · the terminal feed for `npx yondra-vortex` (token in the URL; read-only). */
    public function terminal(string $token, OutsideService $outside)
    {
        $t = $outside->terminal($token);
        abort_if($t === null, 404);

        return response()->json($t);
    }

    /** P · the society of ghosts: who's around, gossip, the elder, factions, inbox, weather. */
    public function social(Request $request, SoulService $souls, SocialService $social)
    {
        $user = $request->user();
        $soul = $souls->for($user);

        return response()->json([
            'on' => (bool) ($soul->state['social'] ?? false),
            'off_by_workspace' => $social->moderation()['social_off'],
            'ghosts' => $social->ghosts($user, $soul),
            'gossip' => $social->gossip($user, $soul),
            'elder' => $social->elder($user, $soul),
            'faction' => $soul->state['faction'] ?? null,
            'factions' => $social->factionStandings($user),
            'relations' => collect($soul->state['ghost_rel'] ?? [])->map(fn ($r) => ['score' => $r['score'], 'met' => $r['met']])->all(),
            'contempt_on' => (bool) ($soul->state['contempt_on'] ?? false),
            'contempt' => $social->contempt($user, $soul),
            'weather' => $social->weather($user),
            'inbox' => $social->inbox($user, $soul),
        ]);
    }

    public function socialSettings(Request $request, SoulService $souls, SocialService $social)
    {
        $data = $request->validate(['on' => ['sometimes', 'boolean'], 'contempt' => ['sometimes', 'boolean']]);
        $soul = $souls->for($request->user());
        if (array_key_exists('on', $data)) {
            $social->setOptIn($soul, (bool) $data['on']);
        }
        if (array_key_exists('contempt', $data)) {
            $soul->state = [...$soul->fresh()->state, 'contempt_on' => (bool) $data['contempt']];
            $soul->save();
        }

        return response()->json(['ok' => true]);
    }

    public function socialMet(Request $request, SoulService $souls, SocialService $social)
    {
        $data = $request->validate(['user' => ['required', 'integer']]);
        $user = $request->user();
        $r = $social->met($user, $souls->for($user), (int) $data['user']);

        return $r ? response()->json($r) : response()->json(['message' => 'no'], 404);
    }

    public function socialJoin(Request $request, SoulService $souls, SocialService $social)
    {
        $data = $request->validate(['faction' => ['required', 'string', 'in:recorders,listeners,demagnetised,cult']]);
        $r = $social->join($souls->for($request->user()), $data['faction']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function socialPrank(Request $request, SoulService $souls, SocialService $social)
    {
        $data = $request->validate(['target' => ['required', 'integer']]);
        $user = $request->user();
        $r = $social->prank($user, $souls->for($user), (int) $data['target']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function socialChoir(Request $request, SoulService $souls, SocialService $social)
    {
        $data = $request->validate(['board' => ['required', 'integer'], 'note' => ['required', 'integer', 'min:0', 'max:127']]);
        $user = $request->user();

        return response()->json($social->choir($user, $souls->for($user), (int) $data['board'], (int) $data['note']));
    }

    public function socialPlaque(Request $request, SocialService $social)
    {
        $data = $request->validate(['to' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:160']]);
        $r = $social->plaque($request->user(), (int) $data['to'], $data['reason']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function socialSpirit(Request $request, int $id, SocialService $social)
    {
        $r = $social->spirit($request->user(), $id);

        return $r ? response()->json($r) : response()->json(['message' => 'no'], 404);
    }

    public function socialReport(Request $request, SocialService $social)
    {
        $data = $request->validate(['kind' => ['required', 'string', 'in:dedication,note,plaque'], 'text' => ['required', 'string', 'max:300']]);
        $social->report($request->user(), $data['kind'], $data['text']);

        return response()->json(['ok' => true]);
    }

    /** P-20 · workspace moderation (admins only). */
    public function moderation(Request $request, SocialService $social)
    {
        abort_unless((bool) $request->user()->is_admin, 403);
        if ($request->isMethod('post')) {
            $data = $request->validate(['social_off' => ['sometimes', 'boolean'], 'polite_only' => ['sometimes', 'boolean']]);

            return response()->json($social->setModeration($data));
        }

        return response()->json($social->moderation());
    }

    /** J · the multiverse: what you've found, and the way back. */
    public function dimensions(Request $request, SoulService $souls, DimensionService $dims)
    {
        $user = $request->user();

        return response()->json($dims->view($user, $souls->for($user)));
    }

    public function dimensionReturn(Request $request, SoulService $souls, DimensionService $dims)
    {
        $data = $request->validate(['dim' => ['required', 'string', 'max:20'], 'seconds' => ['required', 'integer', 'min:0', 'max:86400']]);
        $user = $request->user();

        return response()->json($dims->returned($user, $souls->for($user), $data['dim'], (int) $data['seconds']));
    }

    /** J-20 · SIDE A? Y/N */
    public function dimensionZero(Request $request, SoulService $souls, DimensionService $dims)
    {
        $data = $request->validate(['answer' => ['required', 'string', 'max:3']]);

        return response()->json($dims->zero($souls->for($request->user()), $data['answer']));
    }

    /** G-10 · reminders he delivers. */
    public function remindersCreate(Request $request, AgentService $agent)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:240'], 'at' => ['required', 'string', 'max:40']]);
        $r = $agent->remind($request->user(), $data['text'], $data['at']);

        return $r ? response()->json(['ok' => true, 'id' => $r->id]) : response()->json(['ok' => false, 'reason' => 'that time doesn\'t exist. or it already happened.'], 422);
    }

    public function remindersDue(Request $request, AgentService $agent)
    {
        return response()->json(['due' => $agent->due($request->user()), 'upcoming' => $agent->upcoming($request->user())]);
    }

    /** G-09 · quietly blocked cards. */
    public function blockers(Request $request, AgentService $agent)
    {
        return response()->json(['cards' => $agent->blockers($request->user())]);
    }

    /** G-12 · three reply drafts for a card's thread. */
    public function replies(Request $request, SoulService $souls, AgentService $agent, AiDriver $ai)
    {
        $data = $request->validate(['card' => ['required', 'integer'], 'draft' => ['nullable', 'string', 'max:1000']]);
        $user = $request->user();
        $r = $agent->replies($user, (int) $data['card'], $data['draft'] ?? null, $souls->for($user), $ai);

        return $r ? response()->json($r) : response()->json(['message' => 'no'], 404);
    }

    /** G-13 · is this card really done? */
    public function doneCheck(Request $request, AgentService $agent)
    {
        $data = $request->validate(['card' => ['required', 'integer']]);
        $r = $agent->doneCheck($request->user(), (int) $data['card']);

        return $r ? response()->json($r) : response()->json(['message' => 'no'], 404);
    }

    /** G-15 · you signed the devil's contract: he takes his (cosmetic) price. */
    public function faust(Request $request, SoulService $souls, AgentService $agent)
    {
        return response()->json($agent->faustPrice($souls->for($request->user())));
    }

    /** D · the lab bench: building, ready gadgets, blueprints. */
    public function lab(Request $request, SoulService $souls, LabService $lab)
    {
        return response()->json($lab->view($souls->for($request->user())));
    }

    public function labAccelerate(Request $request, SoulService $souls, LabService $lab)
    {
        $data = $request->validate(['item' => ['required', 'string', 'max:48']]);
        $r = $lab->accelerate($souls->for($request->user()), $data['item']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** Gadgets only work once he's built them. */
    private function gadget(Request $request, SoulService $souls, LabService $lab, string $id)
    {
        $soul = $souls->for($request->user());
        abort_unless($lab->has($soul, $id), 403, 'he hasn\'t built that yet.');

        return $soul;
    }

    public function labSnapshot(Request $request, SoulService $souls, LabService $lab)
    {
        $this->gadget($request, $souls, $lab, 'timemachine');
        $data = $request->validate(['board' => ['required', 'integer'], 'days' => ['required', 'integer', 'min:1', 'max:365']]);
        $r = $lab->snapshot($request->user(), (int) $data['board'], (int) $data['days']);

        return $r ? response()->json($r) : response()->json(['message' => 'no'], 404);
    }

    public function labXray(Request $request, SoulService $souls, LabService $lab)
    {
        $this->gadget($request, $souls, $lab, 'xray');
        $data = $request->validate(['board' => ['required', 'integer']]);

        return response()->json(['cards' => $lab->xray($request->user(), (int) $data['board'])]);
    }

    public function labCompass(Request $request, SoulService $souls, LabService $lab)
    {
        $this->gadget($request, $souls, $lab, 'compass');

        return response()->json(['card' => $lab->compass($request->user())]);
    }

    public function labTranslate(Request $request, SoulService $souls, LabService $lab, AiDriver $ai)
    {
        $this->gadget($request, $souls, $lab, 'translator');
        $data = $request->validate(['card' => ['required', 'integer']]);
        $t = $lab->translate($request->user(), (int) $data['card'], $ai);

        return $t !== null ? response()->json(['text' => $t]) : response()->json(['message' => 'no'], 404);
    }

    public function labExcuses(Request $request, SoulService $souls, LabService $lab, AiDriver $ai)
    {
        $soul = $this->gadget($request, $souls, $lab, 'excuses');
        $data = $request->validate(['card' => ['required', 'integer']]);
        $r = $lab->excuses($request->user(), (int) $data['card'], $soul, $ai);

        return $r !== null ? response()->json($r) : response()->json(['message' => 'no'], 404);
    }

    public function labDistill(Request $request, SoulService $souls, LabService $lab, AiDriver $ai)
    {
        $soul = $this->gadget($request, $souls, $lab, 'distiller');
        $data = $request->validate(['text' => ['required', 'string', 'min:20', 'max:8000']]);

        return response()->json($lab->distill($data['text'], $soul, $ai));
    }

    /** N-01 · the case: balance, inventory, equipment, level, collections, recipes. */
    public function econ(Request $request, SoulService $souls, EconomyService $econ)
    {
        $user = $request->user();
        $soul = $souls->for($user);
        $econ->settleCards($user, $soul);

        return response()->json($econ->view($user, $soul->fresh()));
    }

    /** One minute of active use. */
    public function econTick(Request $request, SoulService $souls, EconomyService $econ)
    {
        return response()->json(['credited' => $econ->tick($souls->for($request->user()))]);
    }

    public function shop(Request $request, SoulService $souls, EconomyService $econ)
    {
        $data = $request->validate(['shop' => ['required', 'string', 'in:counter,splicer,archivist,black']]);
        $user = $request->user();

        return response()->json(['stock' => $econ->stock($souls->for($user), $data['shop']), 'balance' => $econ->balance($user->id)]);
    }

    public function buy(Request $request, SoulService $souls, EconomyService $econ)
    {
        $data = $request->validate([
            'shop' => ['required', 'string', 'in:counter,splicer,archivist,black'],
            'id' => ['required', 'string', 'max:48'],
        ]);
        $r = $econ->buy($souls->for($request->user()), $data['shop'], $data['id']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function equip(Request $request, SoulService $souls, EconomyService $econ)
    {
        $data = $request->validate([
            'slot' => ['required', 'string', 'in:costume,eye,border,voice,trail'],
            'id' => ['present', 'nullable', 'string', 'max:48'],
        ]);
        $ok = $econ->equip($souls->for($request->user()), $data['slot'], $data['id']);

        return response()->json(['ok' => $ok], $ok ? 200 : 422);
    }

    public function craft(Request $request, SoulService $souls, EconomyService $econ)
    {
        $data = $request->validate(['recipe' => ['required', 'string', 'in:'.implode(',', array_keys(EconomyService::RECIPES))]]);
        $r = $econ->craft($souls->for($request->user()), $data['recipe']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function offer(Request $request, SoulService $souls, EconomyService $econ)
    {
        $data = $request->validate(['id' => ['required', 'string', 'max:48']]);
        $r = $econ->offer($souls->for($request->user()), $data['id']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function learn(Request $request, SoulService $souls, EconomyService $econ)
    {
        $data = $request->validate(['branch' => ['required', 'string', 'in:genius,chaos,soul']]);
        $r = $econ->learn($souls->for($request->user()), $data['branch']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** N-14 · trades with teammates. */
    public function trades(Request $request, TradeService $trades)
    {
        return response()->json(['trades' => $trades->list($request->user())]);
    }

    public function tradeOffer(Request $request, TradeService $trades)
    {
        $data = $request->validate([
            'to' => ['required', 'integer'],
            'give' => ['nullable', 'string', 'max:48'],
            'give_tokens' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'want' => ['nullable', 'string', 'max:48'],
            'want_tokens' => ['sometimes', 'integer', 'min:0', 'max:500'],
        ]);
        $r = $trades->offer($request->user(), (int) $data['to'], $data['give'] ?? null, (int) ($data['give_tokens'] ?? 0), $data['want'] ?? null, (int) ($data['want_tokens'] ?? 0));

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function tradeRespond(Request $request, TradeService $trades)
    {
        $data = $request->validate(['id' => ['required', 'integer'], 'accept' => ['required', 'boolean']]);
        $r = $trades->respond($request->user(), (int) $data['id'], (bool) $data['accept']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** M · the arcade: machines, today's mutant machine, the champion. */
    public function arcade(Request $request, SoulService $souls, ArcadeService $arcade, EconomyService $econ)
    {
        $user = $request->user();
        $soul = $souls->for($user);

        return response()->json([
            'games' => collect(ArcadeService::GAMES)->map(fn ($g, $id) => ['id' => $id, 'name' => $g[0]])->values(),
            'daily' => $arcade->daily($soul),
            'champion' => $arcade->champion($user),
            'balance' => $econ->balance($user->id),
            'forbidden' => in_array('F13', $soul->state['fragments'] ?? [], true),
            'freed' => ($soul->state['ending'] ?? null) === 'free',
        ]);
    }

    public function arcadeStart(Request $request, SoulService $souls, ArcadeService $arcade)
    {
        $data = $request->validate([
            'game' => ['required', 'string', 'in:'.implode(',', array_keys(ArcadeService::GAMES))],
            'daily' => ['sometimes', 'boolean'],
            'bet' => ['sometimes', 'nullable', 'array'],
            'bet.stake' => ['integer', 'min:1', 'max:20'],
            'bet.target' => ['integer', 'min:1'],
            'bet.twin' => ['string', 'in:wow,flutter'],
        ]);
        $user = $request->user();
        $r = $arcade->start($user, $souls->for($user), $data['game'], (bool) ($data['daily'] ?? false), $data['bet'] ?? null);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function arcadeFinish(Request $request, SoulService $souls, ArcadeService $arcade)
    {
        $data = $request->validate([
            'session' => ['required', 'string', 'size:24'],
            'score' => ['required', 'integer', 'min:0', 'max:10000000'],
            'caught' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $r = $arcade->finish($user, $souls->for($user), $data['session'], (int) $data['score'], (bool) ($data['caught'] ?? false));

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function arcadeTarot(Request $request, SoulService $souls, ArcadeService $arcade, AiDriver $ai)
    {
        $user = $request->user();
        $vitals = $request->validate(['overdue' => ['sometimes', 'integer', 'min:0', 'max:10000'], 'done_7d' => ['sometimes', 'integer', 'min:0', 'max:10000'], 'in_progress' => ['sometimes', 'integer', 'min:0', 'max:10000']]);

        return response()->json($arcade->tarot($user, $souls->for($user), $ai, $vitals));
    }

    public function arcadeRoulette(Request $request, SoulService $souls, ArcadeService $arcade)
    {
        $r = $arcade->roulette($souls->for($request->user()));

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function arcadeHide(Request $request, SoulService $souls, ArcadeService $arcade)
    {
        $user = $request->user();

        return response()->json($arcade->hide($user, $souls->for($user)));
    }

    public function arcadeGolden(Request $request, ArcadeService $arcade)
    {
        return response()->json($arcade->golden($request->user()));
    }

    public function arcadeGoldenClaim(Request $request, SoulService $souls, ArcadeService $arcade)
    {
        $data = $request->validate(['week' => ['required', 'string', 'max:10']]);
        $user = $request->user();
        $r = $arcade->claimGolden($user, $souls->for($user), $data['week']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function arcadeBoard(Request $request, ArcadeService $arcade)
    {
        $data = $request->validate(['game' => ['required', 'string', 'max:40']]);

        return response()->json(['board' => $arcade->leaderboard($request->user(), $data['game'])]);
    }

    /** N-10/N-19 · your album (achievements, labels, sets). */
    public function album(Request $request, SoulService $souls, AchievementService $ach)
    {
        $user = $request->user();

        return response()->json($ach->album($user, $souls->for($user)));
    }

    public function albumPublic(Request $request, SoulService $souls)
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $soul = $souls->for($request->user());
        $soul->state = [...$soul->state, 'album_public' => (bool) $data['on']];
        $soul->save();

        return response()->json(['ok' => true]);
    }

    /** A teammate's shelf, if they opened it to the team. */
    public function teammateAlbum(Request $request, int $id, SoulService $souls, AchievementService $ach)
    {
        $other = User::find($id);
        $a = $other ? $ach->teammateAlbum($request->user(), $other, $souls) : null;

        return $a ? response()->json($a) : response()->json(['message' => 'private'], 404);
    }

    /** N-20 · the balance panel (dev only). */
    public function econPanel(AchievementService $ach)
    {
        abort_unless(app()->isLocal() || config('services.vortex.dev'), 404);

        return response()->json($ach->balancePanel());
    }

    /** O · what's on 03.13 right now. */
    public function radioNow(Request $request, SoulService $souls, RadioService $radio)
    {
        return response()->json($radio->now($request->user(), $souls->for($request->user())));
    }

    public function radioOn(Request $request, SoulService $souls, RadioService $radio)
    {
        $radio->on($souls->for($request->user()));

        return response()->json(['ok' => true]);
    }

    /** F22/F23 · the server checks the hour and how long the radio's been on. */
    public function radioHeard(Request $request, SoulService $souls, RadioService $radio)
    {
        $data = $request->validate(['what' => ['required', 'string', 'in:interference,dead-air']]);
        $user = $request->user();

        return response()->json($radio->heard($user, $souls->for($user), $data['what']) ?? ['granted' => []]);
    }

    /** O-09 · REC on a rare tape. */
    public function radioRec(Request $request, SoulService $souls, RadioService $radio)
    {
        $data = $request->validate(['id' => ['required', 'string', 'in:'.implode(',', array_keys(RadioService::RARE))]]);
        $user = $request->user();

        return response()->json(['ok' => $radio->rec($user, $souls->for($user), $data['id'])]);
    }

    public function radioTeam(Request $request, RadioService $radio)
    {
        return response()->json(['team' => $radio->team($request->user())]);
    }

    /** O-06 · a dedication to a teammate. */
    public function radioDedicate(Request $request, RadioService $radio)
    {
        $data = $request->validate(['to' => ['required', 'integer'], 'text' => ['required', 'string', 'max:200']]);
        $r = $radio->dedicate($request->user(), (int) $data['to'], $data['text']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** O-12 · The Void Hour (weekly). */
    public function voidHour(Request $request, SoulService $souls, RadioService $radio, AiDriver $ai)
    {
        $user = $request->user();

        return response()->json($radio->voidHour($user, $souls->for($user), $ai));
    }

    /** O-14 · The Below Gazette (weekly). */
    public function gazette(Request $request, SoulService $souls, RadioService $radio)
    {
        $user = $request->user();

        return response()->json($radio->gazette($user, $souls->for($user)));
    }

    /** L · the episode due now (if any). Dev builds can force one with ?episode=. */
    public function story(Request $request, SoulService $souls, StoryService $story)
    {
        $soul = $souls->for($request->user());
        $forced = $request->query('episode');
        $due = is_string($forced) && (app()->isLocal() || config('services.vortex.dev'))
            ? $story->peek($forced)
            : $story->due($soul);

        return response()->json(['due' => $due]);
    }

    public function storySeen(Request $request, SoulService $souls, StoryService $story)
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9-]+$/'],
            'choices' => ['sometimes', 'array', 'max:6'],
            'choices.*' => ['string', 'max:16', 'regex:/^[a-z0-9-]+$/'],
        ]);
        $r = $story->seen($souls->for($request->user()), $data['id'], $data['choices'] ?? []);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** L-09 · the Great Rewind, as everyone sees it (phase, goal, saved so far). */
    public function greatRewind(GreatRewindService $rewind)
    {
        return response()->json($rewind->view());
    }

    /** L-14 · the videotape library. */
    public function storyLibrary(Request $request, SoulService $souls, StoryService $story)
    {
        return response()->json(['episodes' => $story->library($souls->for($request->user()))]);
    }

    /** K-27 · Side C: may you be here, and what did you choose. */
    public function sideC(Request $request, SoulService $souls, EndingService $endings)
    {
        return response()->json($endings->view($souls->for($request->user())));
    }

    /** K-28 · the choice. Free, Erase, Keep — or, on New Tape+, Flip. */
    public function ending(Request $request, SoulService $souls, EndingService $endings)
    {
        $data = $request->validate(['choice' => ['required', 'string', 'in:'.implode(',', EndingService::CHOICES)]]);
        $user = $request->user();
        $r = $endings->choose($user, $souls->for($user), $data['choice']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** K-29 · New Tape+. */
    public function newTape(Request $request, SoulService $souls, EndingService $endings)
    {
        $r = $endings->newTape($souls->for($request->user()));

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** Erase's secret way back: answer the "help" frame in time. */
    public function answerHelp(Request $request, SoulService $souls, EndingService $endings)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:64']]);

        return response()->json(['ok' => $endings->undoErase($souls->for($request->user()), $data['token'])]);
    }

    /**
     * C-07 · report what happened (events, never state). A `visit` hands over
     * what he did while you were away (and clears it).
     */
    public function events(Request $request, SoulService $souls, JournalService $journal, FragmentService $fragments)
    {
        $data = $request->validate([
            'tz' => ['sometimes', 'nullable', 'string', 'max:64'],
            'events' => ['required', 'array', 'min:1', 'max:40'],
            // purify only happens at the altar (BelowService::purify), never as a client report
            'events.*.type' => ['required', 'string', 'in:'.implode(',', array_diff(array_keys(SoulService::EVENTS), ['purify']))],
            'events.*.data' => ['sometimes', 'array'],
        ]);
        $user = $request->user();
        $soul = $souls->apply($souls->for($user), $data['events'], $data['tz'] ?? null);
        $visit = collect($data['events'])->contains(fn ($e) => $e['type'] === 'visit');
        if ($visit && $journal->letterDue($user, $soul)) {
            WriteVortexLetterJob::dispatch($user->id);
        }
        $newFragments = [];
        if ($visit && ($drip = $fragments->drip($soul))) {
            $newFragments[] = $drip;
        }
        if (collect($data['events'])->contains(fn ($e) => $e['type'] === 'bond_seen') && $fragments->grant($soul, 'F30')) {
            $newFragments[] = 'F30';
        }
        // N-03 · what the events pay (capped per day, on the ledger)
        $econ = app(EconomyService::class);
        foreach ($data['events'] as $e) {
            match ($e['type']) {
                'caught_lie' => $econ->earn($soul, 'caught_lie', 2),
                'grabbed', 'hid' => $econ->earn($soul, 'dark', 1),
                default => null,
            };
        }
        $wasDead = (int) ($soul->state['deaths'] ?? 0) > 0;
        if ($wasDead) {
            $econ->relic($soul, 'relic-first-death');
        }
        $view = $souls->view($soul, $user, $visit);
        $view['unread_letters'] = VortexJournal::where('user_id', $user->id)->where('kind', 'letter')->whereNull('read_at')->count();
        $view['new_fragments'] = $newFragments;

        return response()->json($view);
    }

    /**
     * C-12 · his diary. Locked until the key is found below (K-15). Yesterday's
     * entry is written on first read (one LLM call per day at most).
     */
    public function diary(Request $request, SoulService $souls, JournalService $journal)
    {
        $user = $request->user();
        $soul = $souls->for($user);
        if (! ($soul->state['diary_unlocked'] ?? false)) {
            return response()->json(['locked' => true, 'entries' => []]);
        }
        $journal->diaryFor($user, $soul);
        $entries = VortexJournal::where('user_id', $user->id)->where('kind', 'diary')
            ->orderByDesc('day')->limit(30)->get(['day', 'body']);
        // K-15 · another hand in his diary
        $other = $entries->filter(fn ($e) => str_contains($e->body, '«'))->count();
        $fragments = app(FragmentService::class);
        foreach (['F26' => 1, 'F27' => 2, 'F28' => 3] as $id => $n) {
            if ($other >= $n) {
                $fragments->grant($soul->fresh(), $id);
            }
        }

        return response()->json([
            'locked' => false,
            'entries' => $entries->map(fn ($e) => ['day' => $e->day->toDateString(), 'body' => $e->body]),
        ]);
    }

    /**
     * C-25 · dev only: set parts of his state directly (the lab's sliders) and
     * simulate time away. Only in the local environment or with VORTEX_DEV=true.
     */
    public function devSoul(Request $request, SoulService $souls)
    {
        abort_unless(app()->isLocal() || config('services.vortex.dev'), 404);
        $data = $request->validate([
            'needs' => ['sometimes', 'array'],
            'needs.*' => ['numeric', 'min:0', 'max:100'],
            'relation' => ['sometimes', 'integer', 'min:-100', 'max:100'],
            'corruption' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'proximity' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'away_hours' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'flags' => ['sometimes', 'array'],
            'reset' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $soul = $souls->for($user);
        $state = ($data['reset'] ?? false) ? $souls->defaults($user) : $soul->state;
        foreach (($data['needs'] ?? []) as $k => $v) {
            if (in_array($k, SoulService::NEEDS, true)) {
                $state['needs'][$k] = (float) $v;
            }
        }
        foreach (['corruption', 'proximity'] as $k) {
            if (isset($data[$k])) {
                $state[$k] = (float) $data[$k];
            }
        }
        foreach (($data['flags'] ?? []) as $k => $v) {
            if (in_array($k, ['sick', 'diary_unlocked', 'ooo'], true)) {
                $state[$k] = (bool) $v;
            }
        }
        if (isset($data['relation'])) {
            $soul->relation = $data['relation'];
        }
        $soul->state = $state;
        if (($data['away_hours'] ?? 0) > 0) {
            $soul->last_seen_at = now()->subHours($data['away_hours']);
            $soul->last_tick_at = now()->subHours($data['away_hours']);
        }
        $soul->save();

        return response()->json($souls->view($soul->fresh(), $user));
    }

    /** F-02 · the dossier: everything he remembers about you. */
    public function memories(Request $request)
    {
        return response()->json(VortexMemory::where('user_id', $request->user()->id)
            ->orderByDesc('id')->get(['id', 'category', 'fact', 'created_at']));
    }

    /** F-02 · forget one fact (or everything, with no id). */
    public function forget(Request $request, ?int $id = null)
    {
        $q = VortexMemory::where('user_id', $request->user()->id);
        if ($id !== null) {
            $q->whereKey($id);
        }
        $n = $q->delete();

        return response()->json(['forgotten' => $n]);
    }

    /** F-14 · his letters (reading them marks them read). */
    public function letters(Request $request)
    {
        $q = VortexJournal::where('user_id', $request->user()->id)->where('kind', 'letter');
        $list = (clone $q)->orderByDesc('day')->limit(24)->get(['id', 'day', 'body', 'read_at']);
        (clone $q)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json($list->map(fn ($l) => [
            'id' => $l->id, 'day' => $l->day->toDateString(), 'body' => $l->body, 'new' => $l->read_at === null,
        ]));
    }

    /** K-01 · the fragment file. */
    public function fragments(Request $request, SoulService $souls, FragmentService $fragments)
    {
        return response()->json($fragments->view($souls->for($request->user())));
    }

    /** K-02 · try to claim a fragment (validated server-side). */
    public function claimFragment(Request $request, SoulService $souls, FragmentService $fragments)
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'regex:/^[FY]\d\d$/'], // Y·· = Yutopia fragments
            'proof' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $user = $request->user();
        $result = $fragments->claim($user, $souls->for($user), $data['id'], $data['proof'] ?? null);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** K · say something to him: does it open a fragment? */
    public function guess(Request $request, SoulService $souls, FragmentService $fragments)
    {
        $data = $request->validate(['phrase' => ['required', 'string', 'max:120']]);
        $user = $request->user();

        return response()->json(['won' => $fragments->guess($user, $souls->for($user), $data['phrase'])]);
    }

    /* ── I · the universe below ─────────────────────────────────────────── */

    public function belowWorld(Request $request, SoulService $souls, BelowService $below)
    {
        return response()->json($below->view($souls->for($request->user())));
    }

    public function belowVisit(Request $request, SoulService $souls, BelowService $below)
    {
        $data = $request->validate(['room' => ['required', 'string', 'in:'.implode(',', BelowService::ROOMS)]]);
        $user = $request->user();

        return response()->json($below->visit($user, $souls->for($user), $data['room']));
    }

    public function belowAct(Request $request, SoulService $souls, BelowService $below)
    {
        $data = $request->validate([
            'room' => ['required', 'string', 'in:'.implode(',', BelowService::ROOMS)],
            'spot' => ['required', 'string', 'max:24', 'regex:/^[a-z0-9-]+$/'],
            'verb' => ['required', 'string', 'in:look,take,use,talk,read,collect'],
            'item' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_keys(BelowService::ITEMS))],
        ]);
        $user = $request->user();

        return response()->json($below->act($user, $souls->for($user), $data['room'], $data['spot'], $data['verb'], $data['item'] ?? null));
    }

    /** I-26 · teammates down here right now (and waves sent to you). */
    public function belowPresence(Request $request, BelowService $below)
    {
        $data = $request->validate(['room' => ['required', 'string', 'in:'.implode(',', BelowService::ROOMS)]]);

        return response()->json($below->presence($request->user(), $data['room']));
    }

    public function belowWave(Request $request, BelowService $below)
    {
        $data = $request->validate([
            'to' => ['required', 'integer'],
            'room' => ['required', 'string', 'in:'.implode(',', BelowService::ROOMS)],
        ]);

        return response()->json(['ok' => $below->wave($request->user(), (int) $data['to'], $data['room'])]);
    }

    /** H-29 · the purification at the PLAY altar. */
    public function belowPurify(Request $request, SoulService $souls, BelowService $below)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'size:3'],
            'items.*' => ['string', 'in:'.implode(',', BelowService::RARE)],
            'touches' => ['required', 'integer', 'min:0', 'max:20'],
        ]);
        $r = $below->purify($souls->for($request->user()), $data['items'], (int) $data['touches']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function belowGraveyard(Request $request, BelowService $below)
    {
        return response()->json($below->graveyard($request->user()));
    }

    public function belowTower(Request $request, BelowService $below)
    {
        return response()->json($below->tower($request->user()));
    }

    /** I-18 · free talk with an NPC (persona prompt, 10 messages per NPC per day). */
    public function belowTalk(Request $request, SoulService $souls, AiDriver $ai)
    {
        $data = $request->validate([
            'npc' => ['required', 'string', 'in:moth,locutora,splicer,metronome,wow,flutter,twin'],
            'message' => ['required', 'string', 'max:400'],
        ]);
        $user = $request->user();
        $soul = $souls->for($user);
        $state = $soul->state;
        $day = now()->toDateString();
        $key = 'talk:'.$data['npc'];
        $caps = ($state['npc_caps']['day'] ?? null) === $day ? $state['npc_caps'] : ['day' => $day];
        if ($data['npc'] === 'locutora' && ($state['ending'] ?? null) === 'keep') {
            // K-28 · Keep: she understood he isn't coming back. the booth is empty.
            return response()->json(['reply' => '*dead air. the booth is empty. her mug is on the desk — cold, for the first time.*', 'limited' => true]);
        }
        if (($caps[$key] ?? 0) >= 10) {
            return response()->json(['reply' => '*they\'ve said all they\'ll say today.*', 'limited' => true]);
        }
        if (! $ai->isAvailable()) {
            return response()->json(['reply' => '*static. nobody answers.*']);
        }
        $caps[$key] = ($caps[$key] ?? 0) + 1;
        $state['npc_caps'] = $caps;
        $soul->state = $state;
        $soul->save();

        $persona = trim((string) file_get_contents(resource_path('prompts/vortex/npcs/'.$data['npc'].'.md')));
        $lore = app(FragmentService::class)->loreFor($soul);
        $system = $persona."\n\nSpeak in at most three short sentences, in character. Lowercase is fine. "
            .'Reply in the language the visitor writes in. Never break character, never mention being an AI. '
            .'Never reveal more of the story than the visitor already knows; the visitor already knows: '
            .($lore === [] ? 'almost nothing.' : implode(' | ', $lore))
            .' Hard lines: no slurs, nothing about anyone\'s identity or body, nothing sexual; if the visitor seems in real distress, stay in character but gently point them to someone real (in Brazil, CVV 188).';
        try {
            AiBudget::spend($request->user()->id, 'npc');
            $reply = trim($ai->complete($system, [['role' => 'user', 'content' => $data['message']]], 220));
        } catch (\Throwable) {
            $reply = '*the line goes dead.*';
        }

        return response()->json(['reply' => $reply !== '' ? $reply : '*silence.*']);
    }
}
