<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexLedger;
use App\Infrastructure\Models\VortexTrade;
use Illuminate\Support\Facades\DB;

/**
 * N-14 · trading with teammates. One side offers (an item and/or tokens) for
 * something of the other's; the other accepts or declines. Unique items never
 * trade. Everything is re-checked at the moment of accepting.
 */
final class TradeService
{
    public function __construct(private readonly EconomyService $econ, private readonly SoulService $souls) {}

    public function offer(User $from, int $to, ?string $give, int $giveTokens, ?string $want, int $wantTokens): array
    {
        if (! in_array($to, Teammates::of($from->id), true)) {
            return ['ok' => false, 'reason' => 'only with your team.'];
        }
        if (! $give && $giveTokens <= 0) {
            return ['ok' => false, 'reason' => 'offer something.'];
        }
        foreach ([$give, $want] as $id) {
            if ($id !== null && (($this->econ->catalog()[$id]['rarity'] ?? 'unique') === 'unique')) {
                return ['ok' => false, 'reason' => 'that one doesn\'t trade.'];
            }
        }
        if ($give && ! in_array($give, $this->souls->for($from)->state['inventory'] ?? [], true)) {
            return ['ok' => false, 'reason' => 'you don\'t have that.'];
        }
        if (VortexTrade::where('from_user_id', $from->id)->where('status', 'open')->count() >= 5) {
            return ['ok' => false, 'reason' => 'five open offers at most.'];
        }
        $t = VortexTrade::create([
            'from_user_id' => $from->id, 'to_user_id' => $to,
            'give' => $give, 'give_tokens' => max(0, $giveTokens), 'want' => $want, 'want_tokens' => max(0, $wantTokens),
        ]);

        return ['ok' => true, 'id' => $t->id];
    }

    public function respond(User $user, int $id, bool $accept): array
    {
        $t = VortexTrade::where('id', $id)->where('to_user_id', $user->id)->where('status', 'open')->first();
        if (! $t) {
            return ['ok' => false, 'reason' => 'that offer is gone.'];
        }
        if (! $accept) {
            $t->update(['status' => 'declined']);

            return ['ok' => true];
        }

        return DB::transaction(function () use ($t, $user) {
            $from = User::find($t->from_user_id);
            $a = $this->souls->for($from);
            $b = $this->souls->for($user);
            $ia = $a->state['inventory'] ?? [];
            $ib = $b->state['inventory'] ?? [];
            if (($t->give && ! in_array($t->give, $ia, true)) || ($t->want && ! in_array($t->want, $ib, true))) {
                $t->update(['status' => 'void']);

                return ['ok' => false, 'reason' => 'someone doesn\'t have it anymore.'];
            }
            $bal = fn ($uid) => $this->econ->balance($uid)['tokens'];
            if ($bal($from->id) < $t->give_tokens || $bal($user->id) < $t->want_tokens) {
                $t->update(['status' => 'void']);

                return ['ok' => false, 'reason' => 'not enough tokens on one side.'];
            }
            $move = function (array &$src, array &$dst, ?string $item) {
                if ($item === null) {
                    return;
                }
                unset($src[array_search($item, $src, true)]);
                $src = array_values($src);
                $dst[] = $item;
            };
            $move($ia, $ib, $t->give);
            $move($ib, $ia, $t->want);
            $a->state = [...$a->state, 'inventory' => $ia];
            $b->state = [...$b->state, 'inventory' => $ib];
            $a->save();
            $b->save();
            if ($t->give_tokens > 0) {
                $this->econ->spend($from->id, 'tokens', $t->give_tokens, 'trade:'.$t->id);
                VortexLedger::create(['user_id' => $user->id, 'currency' => 'tokens', 'amount' => $t->give_tokens, 'reason' => 'trade:'.$t->id]);
            }
            if ($t->want_tokens > 0) {
                $this->econ->spend($user->id, 'tokens', $t->want_tokens, 'trade:'.$t->id);
                VortexLedger::create(['user_id' => $from->id, 'currency' => 'tokens', 'amount' => $t->want_tokens, 'reason' => 'trade:'.$t->id]);
            }
            $t->update(['status' => 'done']);

            return ['ok' => true];
        });
    }

    public function list(User $user): array
    {
        $cat = $this->econ->catalog();
        $name = fn ($id) => $id ? ($cat[$id]['name'] ?? $id) : null;

        return VortexTrade::with(['from:id,name', 'to:id,name'])
            ->where(fn ($q) => $q->where('to_user_id', $user->id)->orWhere('from_user_id', $user->id))
            ->where('status', 'open')->latest()->limit(20)->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'incoming' => $t->to_user_id === $user->id,
                'with' => (string) ($t->to_user_id === $user->id ? $t->from?->name : $t->to?->name),
                'give' => $name($t->give), 'give_tokens' => $t->give_tokens,
                'want' => $name($t->want), 'want_tokens' => $t->want_tokens,
            ])->all();
    }
}
