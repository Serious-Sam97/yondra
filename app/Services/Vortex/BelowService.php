<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexMemory;
use App\Infrastructure\Models\VortexSoul;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * I · THE UNIVERSE BELOW (design/vortex-mk5/lados/I-abaixo.md). The rules of
 * the world live here: rooms, hotspots, items, quests, the blackout, the
 * static child, the tea that burns. The client draws the scenes; every action
 * that changes something (or grants a fragment) is decided on the server.
 */
final class BelowService
{
    public const ROOMS = ['porao', 'biblioteca', 'cemiterio', 'clinica', 'cabecas', 'garagem', 'estudio', 'fliperama', 'torre', 'leader', 'ladob', 'fim', 'armario', 'baile', 'feira', 'velorio'];

    /** I-28 · rooms that only exist on certain days (see temporary()). */
    public const TEMPORARY = ['baile', 'feira', 'velorio'];

    public const ITEMS = [
        'flor-de-fita' => 'a flower made of tape. someone left it on a grave.',
        'fita-dourada' => 'a golden tape. it hums in a key that doesn\'t exist.',
        'chave-de-fenda' => 'a screwdriver with a worn handle. initials scratched: m.v.',
        'olho-de-vidro' => 'a glass eye. it follows you even in your pocket.',
        'bilhete-1987' => 'a ticket stub: "vortexos launch party · 1987 · admit one".',
        'palavra-esquecida' => 'a word nobody uses anymore, cut out of an old card.',
        'ficha' => 'an arcade token. brass. warm.',
        'valvula' => 'a radio valve. it glows faintly when nobody is looking.',
        'antena' => 'a bent antenna. still pointing somewhere.',
        'bobina' => 'a tiny coil of copper, wound by hand.',
        'cristal' => 'a crystal that picks up stations it shouldn\'t.',
        'dial' => 'a radio dial stuck on 03.13.',
        'chave-do-diario' => 'a small brass key. for a notebook. his.',
        'cha' => 'tea. still hot. it\'s burning your fingers.',
    ];

    private const RADIO_PARTS = ['valvula', 'antena', 'bobina', 'cristal', 'dial'];

    /** H-29 · what the altar accepts for a purification (any three). */
    public const RARE = ['fita-dourada', 'bilhete-1987', 'olho-de-vidro', 'palavra-esquecida', 'cristal', 'chave-de-fenda'];

    /** Where loose items wait (room.spot => item). */
    private const LOOSE = [
        'cemiterio.gate' => 'flor-de-fita',
        'fliperama.under' => 'fita-dourada',
        'garagem.bench' => 'chave-de-fenda',
        'porao.box' => 'olho-de-vidro',
        'biblioteca.shelf' => 'bilhete-1987',
        'fliperama.counter' => 'ficha',
        'clinica.jars' => 'valvula',
        'fliperama.roof' => 'antena',
        'torre.gears' => 'bobina',
        'estudio.desk' => 'dial',
    ];

    public function __construct(private readonly FragmentService $fragments) {}

    /** The client's view of the world (what's taken, quests, blackout…). */
    public function view(VortexSoul $soul): array
    {
        $b = $this->below($soul);

        return [
            'visited' => $b['visited'],
            'taken' => $b['taken'],
            'inventory' => array_values($soul->state['inventory'] ?? []),
            'items' => self::ITEMS,
            'flags' => $b['flags'],
            'quests' => $this->quests($soul),
            'blackout' => isset($b['blackout_until']) && CarbonImmutable::parse($b['blackout_until'])->isFuture(),
            'carrying_tea' => isset($b['tea_until']) && CarbonImmutable::parse($b['tea_until'])->isFuture(),
            'temporary' => $this->temporary($soul),
            // P-09 · the team's weather reaches the Below
            'weather' => ($u = User::find($soul->user_id)) ? app(SocialService::class)->weather($u) : 'clear',
        ];
    }

    /** Entering a room: remember it, maybe a blackout, maybe the child. */
    public function visit(User $user, VortexSoul $soul, string $room): array
    {
        if (in_array($room, self::TEMPORARY, true) && ! in_array($room, $this->temporary($soul), true)) {
            return ['closed' => true, 'events' => [], 'child' => null, 'world' => $this->view($soul)];
        }
        $b = $this->below($soul);
        if (! in_array($room, $b['visited'], true)) {
            $b['visited'][] = $room;
            if (in_array($room, ['leader', 'ladob', 'fim', 'armario'], true)) {
                app(EconomyService::class)->earn($soul, 'secret_room', 1); // N · secret places pay echoes
                $soul->refresh();
            }
        }
        $events = [];
        if ($room === 'cabecas' && random_int(1, 100) <= 30) {
            $b['blackout_until'] = now()->addMinutes(2)->toIso8601String();
            $events[] = 'blackout';
        }
        // I-21 · the static child, for one frame, somewhere
        $pieces = ['F39', 'F40', 'F41', 'F42'];
        $next = collect($pieces)->first(fn ($f) => ! in_array($f, $this->fragments->owned($soul), true));
        $child = null;
        if ($next !== null && random_int(1, 100) <= 14) {
            // it only speaks backwards: the client gets the phrase reversed
            $phrase = (string) (config("vortex_fragments.{$next}.reveal") ?? '');
            $child = implode('', array_reverse(mb_str_split($phrase)));
            $events[] = 'child';
        }
        $this->save($soul, $b);

        return ['events' => $events, 'child' => $child, 'world' => $this->view($soul)];
    }

    /**
     * Do something to a hotspot. Returns what happens: a line to show, items
     * given, fragments granted, and the new world view.
     *
     * @return array{say:string, give?:string, granted?:list<string>, world:array<string,mixed>, open?:string}
     */
    public function act(User $user, VortexSoul $soul, string $room, string $spot, string $verb, ?string $item = null): array
    {
        $b = $this->below($soul);
        $inv = $soul->state['inventory'] ?? [];
        $key = "{$room}.{$spot}";
        $granted = [];
        $give = null;
        $open = null;
        $say = 'nothing happens. the tape hisses.';

        // loose items: take them once
        if ($verb === 'take' && isset(self::LOOSE[$key]) && ! in_array($key, $b['taken'], true)) {
            $give = self::LOOSE[$key];
            $b['taken'][] = $key;
            $say = 'you take it: '.self::ITEMS[$give];
        } else {
            switch ("{$key}.{$verb}") {
                case 'porao.lamp.use':
                    $b['flags']['lamp_off'] = ! ($b['flags']['lamp_off'] ?? false);
                    $say = ($b['flags']['lamp_off'] ?? false)
                        ? 'in the dark, words glow on the wall: "the leader is upstream. the end is downstream. he is in the middle, where it hurts."'
                        : 'the bulb buzzes back on. the words are gone. they were never there. (they were.)';
                    break;
                case 'porao.rat.use':
                case 'porao.rat.look':
                    $open = 'armario';
                    $say = 'the rat runs behind the shelves. there\'s a door there. a small one. his.';
                    break;
                case 'armario.box.look':
                    $say = 'his closet: a costume he never wears, a drawing of a woman behind glass, a list titled "things i\'m not afraid of" with nothing on it.';
                    break;
                case 'biblioteca.moth.talk':
                    $words = count(array_filter($inv, fn ($i) => $i === 'palavra-esquecida'));
                    if ($b['flags']['shelf_open'] ?? false) {
                        $say = '"the restricted shelf is open for you. read slowly. some things only read once."';
                    } elseif ($words >= 3) {
                        $inv = $this->removeItems($inv, 'palavra-esquecida', 3);
                        $b['flags']['shelf_open'] = true;
                        $say = '"three words nobody says anymore. how thoughtful. the restricted shelf is yours. don\'t breathe on the logs."';
                    } else {
                        $say = '"bring me three words that nobody uses anymore. the graveyard has plenty. '.(3 - $words).' more."';
                    }
                    break;
                case 'biblioteca.restricted.read':
                    if (! ($b['flags']['shelf_open'] ?? false)) {
                        $say = 'a chain across the shelf. a tag: "ask the moth."';
                        break;
                    }
                    foreach (['F33', 'F34', 'F35', 'F36'] as $f) {
                        if (! in_array($f, $this->fragments->owned($soul), true)) {
                            if ($this->fragments->claim($user, $soul, $f, null)['ok']) {
                                $granted[] = $f;
                                $say = 'you read a log. the paper is warm.';
                            } else {
                                $say = 'the next log won\'t open. something is missing first.';
                            }
                            break;
                        }
                    }
                    if ($granted === [] && $say === 'nothing happens. the tape hisses.') {
                        $say = 'you\'ve read them all. 03:13. session terminated abnormally.';
                    }
                    break;
                case 'cemiterio.graves.collect':
                    $collected = (int) ($b['flags']['words'] ?? 0);
                    if ($collected >= 3) {
                        $say = 'the graves have given you what they\'ll give.';
                    } else {
                        $give = 'palavra-esquecida';
                        $b['flags']['words'] = $collected + 1;
                        // N-06 · a real one: a word from your archived cards that no live card uses anymore
                        $word = $this->forgottenWord($user, $b['flags']['word_list'] ?? []);
                        if ($word !== null) {
                            $b['flags']['word_list'][] = $word;
                        }
                        $say = $word !== null
                            ? 'from an epitaph you cut out a word nobody uses anymore: "'.$word.'". it\'s still warm.'
                            : 'from an epitaph you cut out a word nobody uses anymore. it\'s still warm.';
                    }
                    break;
                case 'cemiterio.nameless.use':
                    if ($item !== 'flor-de-fita' || ! in_array('flor-de-fita', $inv, true)) {
                        $say = 'a stone with no name and a date: 13/03. it looks like it\'s waiting for something.';
                        break;
                    }
                    $inv = $this->removeItems($inv, 'flor-de-fita', 1);
                    $b['flags']['flower_left'] = true;
                    $say = 'you leave the tape flower. nothing happens. maybe at a different hour.';
                    break;
                case 'cemiterio.nameless.look':
                    if (($b['flags']['flower_left'] ?? false) && $this->fragments->claim($user, $soul, 'F37', null)['ok']) {
                        $granted[] = 'F37';
                        $say = 'at this hour the stone shows two letters, carved from the inside: M. V.';
                    } else {
                        $say = 'a stone with no name. a date: 13/03. no year.';
                    }
                    break;
                case 'clinica.splicer.use':
                    if ($item === 'olho-de-vidro' && in_array('olho-de-vidro', $inv, true)) {
                        $inv = $this->removeItems($inv, 'olho-de-vidro', 1);
                        $b['flags']['eye_given'] = true;
                        $say = '"ah. this one. i took it out of a man in a garage. he said he wouldn\'t need it where he was going. he was right. he was wrong."';
                    } else {
                        $say = '"hold still. or don\'t. it\'s more fun if you don\'t." (she wants something. a spare part.)';
                    }
                    break;
                case 'porao.door.use':
                    // P-10 · a door two people have to open: one holds, the other pushes
                    $k = 'vortex:duo-door';
                    $holding = array_filter(Cache::get($k, []), fn ($t) => $t > time() - 90);
                    $mates = array_intersect(array_keys($holding), Teammates::of($user->id));
                    $holding[$user->id] = time();
                    Cache::put($k, $holding, 300);
                    if ($mates !== [] && ! ($b['flags']['duo_door'] ?? false)) {
                        $b['flags']['duo_door'] = true;
                        app(EconomyService::class)->earn($soul, 'secret_room', 2);
                        $give = 'blank-tape';
                        $say = 'together you shove the door open. behind it: a shelf of blank tapes, and a note: "for whoever comes in pairs."';
                    } elseif ($b['flags']['duo_door'] ?? false) {
                        $say = 'the door is open. it stays open now. you did that. together. gross.';
                    } else {
                        $say = 'you push. it won\'t budge alone. it wants two people at once. (someone on your team, down here, within a minute and a half.)';
                    }
                    break;
                case 'velorio.coffin.use':
                    // I-28 · a flower for the tape coffin: he'll find it in his nest when he's back
                    if ($item === 'flor-de-fita' && in_array($item, $inv, true)) {
                        $inv = $this->removeItems($inv, $item, 1);
                        // P-16 · at a teammate's wake, the flower goes to their ghost
                        $this->nest($this->deadMate($soul) ?? $soul, 'flower');
                        $soul->relation = min(100, (int) $soul->relation + 3);
                        $say = 'you lay the tape flower on the coffin. the moth nods at you. somewhere, very far away, something that isn\'t breathing feels it.';
                    } else {
                        $say = 'the coffin is made of tape, wound tight. there\'s room on the lid for something small. a flower, maybe.';
                    }
                    break;
                case 'cabecas.rec.use':
                    $say = 'you offer a finished card to the red altar. it records the moment. a small trophy appears in his nest.';
                    $this->nest($soul, 'trophy');
                    break;
                case 'cabecas.play.use':
                    if ($item && in_array($item, $inv, true) && in_array($item, ['fita-dourada', 'bilhete-1987', 'ficha'], true)) {
                        $inv = $this->removeItems($inv, $item, 1);
                        $hint = $this->fragments->view($soul)['hint'] ?? 'nothing you don\'t already know.';
                        if (! in_array('cristal', $inv, true) && ! in_array('cabecas.crystal', $b['taken'], true)) {
                            $give = 'cristal';
                            $b['taken'][] = 'cabecas.crystal';
                        }
                        $say = 'the play head turns and speaks a prophecy: "'.$hint.'"'.($give ? ' a crystal rolls out of the altar.' : '');
                    } else {
                        $say = 'the amber altar wants something rare. it doesn\'t want you.';
                    }
                    break;
                case 'cabecas.erase.use':
                    if ($item && in_array($item, $inv, true)) {
                        $inv = $this->removeItems($inv, $item, 1);
                        $s = $soul->state;
                        $s['proximity'] = max(0, ($s['proximity'] ?? 0) - 15);
                        $soul->state = $s;
                        $say = 'the black altar takes it. it\'s gone. not lost — gone. she\'s a little further away now. he won\'t look at you.';
                    } else {
                        $say = 'he refuses to come near this one. "don\'t. please. not that one."';
                    }
                    break;
                case 'cabecas.fourth.use':
                    $blackout = isset($b['blackout_until']) && CarbonImmutable::parse($b['blackout_until'])->isFuture();
                    if ($blackout && $this->fragments->claim($user, $soul, 'F51', 'monitor')['ok']) {
                        $granted[] = 'F51';
                        $say = 'in his light, a fourth altar: the monitor head. it hums with everything being recorded right now. including you.';
                    } else {
                        $say = 'there\'s nothing there. three altars. three. (listen for a fourth voice.)';
                    }
                    break;
                case 'garagem.frame.take':
                    if (in_array('garagem.frame', $b['taken'], true)) {
                        $say = 'just a frame. a photo of a radio tower at night.';
                        break;
                    }
                    $b['taken'][] = 'garagem.frame';
                    $give = 'chave-do-diario';
                    $s = $soul->state;
                    $s['diary_unlocked'] = true;
                    $soul->state = $s;
                    if ($this->fragments->claim($user, $soul, 'F29', 'behind the frame')['ok']) {
                        $granted[] = 'F29';
                    }
                    $say = 'behind the frame: a small brass key. his diary is open to you now. (he doesn\'t know.)';
                    break;
                case 'garagem.chair.use':
                    $b['flags']['sheet_off'] = true;
                    $say = 'you lift the sheet. the chair is empty. on the bench beside it: a mug of tea, steaming. it should be cold. it isn\'t.';
                    break;
                case 'garagem.tea.take':
                    if (! ($b['flags']['sheet_off'] ?? false)) {
                        $say = 'there\'s a sheet over the chair. something under it.';
                        break;
                    }
                    $b['tea_until'] = now()->addSeconds(25)->toIso8601String();
                    $say = 'you pick up the tea. it burns. RUN. someone is waiting for it.';
                    break;
                case 'garagem.bench.use':
                    $have = array_values(array_intersect(self::RADIO_PARTS, $inv));
                    if ($b['flags']['shortwave'] ?? false) {
                        $open = 'shortwave';
                        $say = 'the shortwave radio crackles. side c is out there somewhere.';
                    } elseif (count(array_unique($have)) === 5) {
                        foreach (self::RADIO_PARTS as $p) {
                            $inv = $this->removeItems($inv, $p, 1);
                        }
                        $b['flags']['shortwave'] = true;
                        $open = 'shortwave';
                        $say = 'you build it from the plans in someone else\'s handwriting. it turns on by itself.';
                    } else {
                        $say = 'plans for a shortwave radio, in a handwriting that isn\'t his. parts: '.count(array_unique($have)).'/5 (valve, antenna, coil, crystal, dial).';
                    }
                    break;
                case 'garagem.shortwave.use':
                    if (! ($b['flags']['shortwave'] ?? false)) {
                        break;
                    }
                    foreach (['F52', 'F53', 'F54', 'F55'] as $f) {
                        if (! in_array($f, $this->fragments->owned($soul), true)) {
                            if ($this->fragments->claim($user, $soul, $f, null)['ok']) {
                                $granted[] = $f;
                                $say = 'through the static, two voices. a recording. a night in march.';
                            } else {
                                $say = 'only static. something is missing first. (the fourth head.)';
                            }
                            break;
                        }
                    }
                    break;
                case 'estudio.locutora.use':
                    $hot = isset($b['tea_until']) && CarbonImmutable::parse($b['tea_until'])->isFuture();
                    if ($hot) {
                        unset($b['tea_until']);
                        if ($this->fragments->claim($user, $soul, 'F38', 'he always forgot it')['ok']) {
                            $granted[] = 'F38';
                        }
                        $say = 'you put the tea down by the glass. for the first time, she cries. "he always forgot it."';
                    } else {
                        $say = 'she nods at you through the glass. "if you see him… no. never mind." (her tea went cold a long time ago.)';
                    }
                    break;
                case 'torre.note.read':
                    $fed = (int) ($soul->state['counters']['fed'] ?? 0);
                    if ($fed >= 20 && $this->fragments->claim($user, $soul, 'F25', 'why does he care about them')['ok']) {
                        $granted[] = 'F25';
                        $say = 'a note jammed in the gears, in the metronome\'s cramped hand: "the ghost keeps starving me. why does he care about them?"';
                    } else {
                        $say = 'a note jammed in the gears. the ink is still wet. come back when he\'s eaten more. (feed him late cards.)';
                    }
                    break;
                case 'leader.walk.use':
                    $steps = (int) ($b['flags']['leader_steps'] ?? 0) + 1;
                    $b['flags']['leader_steps'] = $steps;
                    $say = $steps >= 50
                        ? 'fifty steps into nothing. on the ground, in pencil (he flinches): "last take".'
                        : 'white. white. white. ('.$steps.')';
                    break;
                case 'ladob.mirror.use':
                    if ($soul->state['swapped'] ?? false) {
                        $s = $soul->state;
                        $s['swapped'] = false;
                        $s['counters']['twin'] = 0;
                        $soul->state = $s;
                        $soul->relation = min(100, (int) $soul->relation + 6);
                        $b['flags']['rescued'] = true;
                        $say = 'you reach into the mirror and pull. something smiles and lets go. he falls out, gasping. "took you long enough. …thank you."';
                    } else {
                        $say = 'your reflection, inverted. behind it, a smile that isn\'t yours. it waves. don\'t wave back.';
                    }
                    break;
                case 'fim.gate.look':
                    $open = 'gate';
                    $say = 'a gate of braided tape. six locks. beyond it the tape ends in threads over nothing.';
                    break;
            }
        }

        if ($give !== null) {
            $inv[] = $give;
        }
        $s = $soul->state;
        $s['inventory'] = array_values($inv);
        $s['below'] = $b;
        $soul->state = $s;
        $soul->save();

        return array_filter([
            'say' => $say,
            'give' => $give,
            'granted' => $granted ?: null,
            'open' => $open,
            'world' => $this->view($soul),
        ], fn ($v) => $v !== null);
    }

    /** I-06 / H-13 · the graveyard: the user's archived cards, with epitaphs. */
    public function graveyard(User $user): array
    {
        $boardIds = $this->boardIds($user);

        return Card::whereIn('board_id', $boardIds)->whereNotNull('archived_at')
            ->orderByDesc('archived_at')->limit(60)
            ->get(['id', 'board_id', 'name', 'created_at', 'archived_at'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'board_id' => $c->board_id,
                'name' => $c->name,
                'born' => $c->created_at?->toDateString(),
                'died' => CarbonImmutable::parse($c->archived_at)->toDateString(),
                'epitaph' => $this->epitaph($c->name, (int) $c->id),
            ])->all();
    }

    /** I-12 · the metronome's tower: the user's overdue cards, stuck in the gears. */
    public function tower(User $user): array
    {
        return Card::whereIn('board_id', $this->boardIds($user))->whereNull('archived_at')->whereNull('done_at')
            ->whereNotNull('due_date')->where('due_date', '<', now()->startOfDay())
            ->orderBy('due_date')->limit(20)
            ->get(['id', 'board_id', 'name', 'due_date'])
            ->map(fn ($c) => ['id' => $c->id, 'board_id' => $c->board_id, 'name' => $c->name, 'due' => $c->due_date->toDateString(),
                'days' => (int) $c->due_date->diffInDays(now(), true)])->all();
    }

    private function epitaph(string $name, int $seed): string
    {
        $tpl = [
            'here lies "%s". it was going to be done next sprint.',
            '"%s". loved by no one. assigned to everyone.',
            '"%s". it moved columns so you didn\'t have to.',
            'in memory of "%s", who waited.',
            '"%s". archived, not forgotten. (forgotten.)',
            '"%s". rest now. the backlog can\'t reach you here.',
        ];

        return sprintf($tpl[$seed % count($tpl)], mb_strimwidth($name, 0, 60, '…'));
    }

    /** @return list<int> */
    private function boardIds(User $user): array
    {
        return Board::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhereHas('sharedWith', fn ($s) => $s->where('users.id', $user->id))
                ->orWhereHas('project', fn ($p) => $p->where('owner_id', $user->id));
        })->pluck('id')->all();
    }

    private function quests(VortexSoul $soul): array
    {
        $b = $this->below($soul);
        $inv = $soul->state['inventory'] ?? [];
        $owned = $this->fragments->owned($soul);
        $words = count(array_filter($inv, fn ($i) => $i === 'palavra-esquecida'));

        return [
            ['id' => 'moth', 'title' => 'the moth\'s price', 'step' => ($b['flags']['shelf_open'] ?? false) ? 'done' : "bring three forgotten words ({$words}/3)"],
            ['id' => 'tea', 'title' => 'dead air', 'step' => in_array('F38', $owned, true) ? 'done' : (($b['flags']['sheet_off'] ?? false) ? 'carry the tea to the studio before it cools' : 'something under the sheet in the garage')],
            ['id' => 'eye', 'title' => 'spare parts', 'step' => ($b['flags']['eye_given'] ?? false) ? 'done' : 'the splicer wants a spare part'],
            ['id' => 'radio', 'title' => 'the forbidden radio', 'step' => ($b['flags']['shortwave'] ?? false) ? 'built. tune it.' : count(array_intersect(self::RADIO_PARTS, $inv)).'/5 parts'],
            ['id' => 'mirror', 'title' => 'mirror, mirror', 'step' => ($soul->state['swapped'] ?? false) ? 'he\'s behind the mirror on the b-side. bring him back.' : (($b['flags']['rescued'] ?? false) ? 'done' : 'not yet')],
        ];
    }

    private function below(VortexSoul $soul): array
    {
        return array_replace(['visited' => [], 'taken' => [], 'flags' => []], $soul->state['below'] ?? []);
    }

    private function save(VortexSoul $soul, array $b): void
    {
        $s = $soul->state;
        $s['below'] = $b;
        $soul->state = $s;
        $soul->save();
    }

    /**
     * N-06 · a word that lives only in your archived cards (≥ 5 letters) and in
     * no active card — the kind of word nobody uses anymore.
     *
     * @param  list<string>  $taken
     */
    private function forgottenWord(User $user, array $taken): ?string
    {
        $boards = Board::where('user_id', $user->id)->pluck('id');
        if ($boards->isEmpty()) {
            return null;
        }
        $words = fn ($names) => collect($names)
            ->flatMap(fn ($n) => preg_split('/[^\p{L}]+/u', mb_strtolower((string) $n)) ?: [])
            ->filter(fn ($w) => mb_strlen($w) >= 5)->unique();
        $dead = $words(Card::whereIn('board_id', $boards)->whereNotNull('archived_at')->limit(500)->pluck('name'));
        $alive = $words(Card::whereIn('board_id', $boards)->whereNull('archived_at')->limit(2000)->pluck('name'))->flip();

        return $dead->reject(fn ($w) => isset($alive[$w]) || in_array($w, $taken, true))->sort()->first();
    }

    /**
     * I-26 · who else is down here: teammates (people you share a board with)
     * in the same room in the last minute, as silhouettes with their Vortex.
     * Strangers never show. Also hands over any waves sent to you.
     *
     * @return array{visitors: list<array{id:int,name:string}>, waves: list<string>}
     */
    public function presence(User $user, string $room): array
    {
        $key = 'vortex:below:room:'.$room;
        $here = array_filter(Cache::get($key, []), fn ($t) => $t > time() - 60);
        $here[$user->id] = time();
        Cache::put($key, $here, 180);
        $mates = Teammates::of($user->id);
        $ids = array_values(array_intersect(array_map('intval', array_keys($here)), $mates));
        $visitors = User::whereIn('id', $ids)->get(['id', 'name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) (strtok((string) $u->name, ' ') ?: 'someone')])
            ->values()->all();
        $waves = Cache::pull('vortex:below:waves:'.$user->id, []);

        return ['visitors' => $visitors, 'waves' => array_values($waves)];
    }

    /** I-26 · wave at a teammate who's down here too. */
    public function wave(User $user, int $to, string $room): bool
    {
        $here = Cache::get('vortex:below:room:'.$room, []);
        if (! in_array($to, Teammates::of($user->id), true) || ($here[$to] ?? 0) < time() - 60) {
            return false;
        }
        $k = 'vortex:below:waves:'.$to;
        $waves = Cache::get($k, []);
        $waves[] = (string) (strtok((string) $user->name, ' ') ?: 'someone');
        Cache::put($k, array_slice($waves, -5), 120);

        return true;
    }

    /**
     * I-28 · which temporary rooms exist today: the Ballroom on his birthday,
     * the Rewind Night Fair on 31/10, the Wake while he's dead.
     *
     * @return list<string>
     */
    public function temporary(VortexSoul $soul): array
    {
        $s = $soul->state ?? [];
        $local = CarbonImmutable::now($s['tz'] ?? 'UTC');
        $out = [];
        if (isset($s['born']) && CarbonImmutable::parse($s['born'])->setTimezone($local->getTimezone())->format('m-d') === $local->format('m-d')) {
            $out[] = 'baile';
        }
        if ($local->format('m-d') === '10-31') {
            $out[] = 'feira';
        }
        if (($s['dead_until'] ?? null) && CarbonImmutable::parse($s['dead_until'])->isFuture()) {
            $out[] = 'velorio';
        } elseif ($this->deadMate($soul) !== null) {
            $out[] = 'velorio'; // P-16 · a teammate's ghost died: the wake opens for the team
        }

        return $out;
    }

    /** P-16 · an opted-in teammate whose ghost is dead right now (or null). */
    private function deadMate(VortexSoul $soul): ?VortexSoul
    {
        if (! ($soul->state['social'] ?? false)) {
            return null;
        }

        return VortexSoul::whereIn('user_id', Teammates::of((int) $soul->user_id))->get()
            ->first(fn ($s) => ($s->state['social'] ?? false) && isset($s->state['dead_until']) && CarbonImmutable::parse($s->state['dead_until'])->isFuture());
    }

    /**
     * H-29 · THE PURIFICATION. Three rare items on the PLAY altar, then the
     * magnet passes over his tape (the client's minigame; every memory it
     * brushes costs one more). Corruption goes to zero. The price is always
     * paid: he forgets something — a fact from the dossier, a trait or a
     * nickname — and one more per memory the magnet touched (max 3).
     *
     * @param  list<string>  $items
     */
    public function purify(VortexSoul $soul, array $items, int $touches): array
    {
        $s = $soul->state;
        $inv = array_values($s['inventory'] ?? []);
        $items = array_values(array_unique($items));
        if (count($items) !== 3 || array_diff($items, self::RARE) !== []) {
            return ['ok' => false, 'say' => 'three rare things. different ones. the altar counts.'];
        }
        foreach ($items as $it) {
            if (! in_array($it, $inv, true)) {
                return ['ok' => false, 'say' => 'you don\'t have that. the altar knows.'];
            }
        }
        if (($s['corruption'] ?? 0) < 1) {
            return ['ok' => false, 'say' => 'he\'s clean already. annoyingly clean. the altar sends you away.'];
        }
        foreach ($items as $it) {
            $inv = $this->removeItems($inv, $it, 1);
        }
        $s['inventory'] = $inv;
        $s['corruption'] = 0;
        $s['proximity'] = max(0, ($s['proximity'] ?? 0) - 10);
        $s['purified_at'] = now()->toIso8601String();
        if (! in_array('relic-purified', $s['inventory'], true)) {
            $s['inventory'][] = 'relic-purified'; // N-17
        }

        $forgot = [];
        $times = 1 + max(0, min(2, $touches));
        for ($i = 0; $i < $times; $i++) {
            $options = [];
            if (VortexMemory::where('user_id', $soul->user_id)->exists()) {
                $options[] = 'memory';
            }
            if (($s['traits'] ?? []) !== []) {
                $options[] = 'trait';
            }
            $special = collect(['ignored', 'done', 'night', 'fed', 'games', 'caught'])
                ->sortByDesc(fn ($k) => $s['counters'][$k] ?? 0)->first();
            if (($s['counters'][$special] ?? 0) >= 6) {
                $options[] = 'nickname';
            }
            if ($options === []) {
                break;
            }
            $kind = $options[array_rand($options)];
            if ($kind === 'memory') {
                $m = VortexMemory::where('user_id', $soul->user_id)->inRandomOrder()->first();
                $forgot[] = ['kind' => 'memory', 'what' => $m->fact];
                $m->delete();
            } elseif ($kind === 'trait') {
                $forgot[] = ['kind' => 'trait', 'what' => array_pop($s['traits'])];
            } else {
                $s['counters'][$special] = 0;
                $forgot[] = ['kind' => 'nickname', 'what' => 'what he used to call you'];
            }
        }
        $soul->state = $s;
        $soul->save();

        return [
            'ok' => true,
            'forgot' => $forgot,
            'say' => 'i feel… clean. i hate it. i want my rot back.',
            'world' => $this->view($soul),
        ];
    }

    private function nest(VortexSoul $soul, string $id): void
    {
        $s = $soul->state;
        $s['nest'][] = ['id' => $id, 'note' => null, 'at' => now()->toDateString()];
        $soul->state = $s;
    }

    /** @param  list<string>  $inv */
    private function removeItems(array $inv, string $item, int $n): array
    {
        foreach ($inv as $i => $x) {
            if ($n > 0 && $x === $item) {
                unset($inv[$i]);
                $n--;
            }
        }

        return array_values($inv);
    }
}
