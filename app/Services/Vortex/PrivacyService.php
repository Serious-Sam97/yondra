<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\VortexDedication;
use App\Infrastructure\Models\VortexJournal;
use App\Infrastructure\Models\VortexLedger;
use App\Infrastructure\Models\VortexMemory;
use App\Infrastructure\Models\VortexNote;
use App\Infrastructure\Models\VortexReminder;
use App\Infrastructure\Models\VortexScore;
use App\Infrastructure\Models\VortexSoul;
use App\Infrastructure\Models\VortexTrade;
use Illuminate\Support\Facades\DB;

/**
 * T-04 · "forget everything". Deletes everything Vortex knows about you —
 * his soul (needs, traits, scars, tricks, taught lines, taste, costume,
 * settings for the outside channels), the dossier, the diary, the letters
 * you sent and received, reminders, dedications, trades, game scores and the
 * economy ledger. Optionally the achievements you earned survive, frozen.
 * Next time he wakes up he's a stranger. (He'll pretend he isn't.)
 */
final class PrivacyService
{
    public function __construct(private readonly AchievementService $achievements) {}

    /** @return array{deleted: array<string, int>, kept: int} */
    public function forget(User $user, bool $keepAchievements): array
    {
        $kept = [];
        if ($keepAchievements && ($soul = VortexSoul::where('user_id', $user->id)->first())) {
            $kept = array_values(array_map(
                fn ($a) => $a['id'],
                array_filter($this->achievements->list($user, $soul), fn ($a) => $a['got']),
            ));
        }
        $id = $user->id;
        $deleted = DB::transaction(fn () => [
            'soul' => VortexSoul::where('user_id', $id)->delete(),
            'memories' => VortexMemory::where('user_id', $id)->delete(),
            'diary' => VortexJournal::where('user_id', $id)->delete(),
            'letters' => VortexNote::where('from_user_id', $id)->orWhere('to_user_id', $id)->delete(),
            'reminders' => VortexReminder::where('user_id', $id)->delete(),
            'push' => DB::table('vortex_push_subscriptions')->where('user_id', $id)->delete(),
            'dedications' => VortexDedication::where('from_user_id', $id)->orWhere('to_user_id', $id)->delete(),
            'trades' => VortexTrade::where('from_user_id', $id)->orWhere('to_user_id', $id)->delete(),
            'scores' => VortexScore::where('user_id', $id)->delete(),
            'ledger' => VortexLedger::where('user_id', $id)->delete(),
        ]);
        if ($kept !== []) {
            $soul = app(SoulService::class)->for($user);
            $s = $soul->state;
            $s['kept_achievements'] = $kept;
            $soul->state = $s;
            $soul->save();
        }

        return ['deleted' => $deleted, 'kept' => count($kept)];
    }
}
