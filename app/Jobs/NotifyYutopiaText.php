<?php

namespace App\Jobs;

use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YutopiaMessage;
use App\Infrastructure\Models\YutopiaThreadMember;
use App\Notifications\YutopiaTextNotification;
use App\Services\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

// A text on Yutopia's phone that nobody read for 10 minutes: whoever still
// hasn't gets a Yondra notification (their preferences decide where).
class NotifyYutopiaText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $messageId) {}

    public function handle(Notifier $notifier): void
    {
        $m = YutopiaMessage::find($this->messageId);
        if (! $m || ! $m->user_id) {
            return;
        }
        $from = User::find($m->user_id);
        $unread = YutopiaThreadMember::where('thread_id', $m->thread_id)
            ->where('user_id', '!=', $m->user_id)->whereNull('left_at')->where('muted', false)
            ->where('last_read_id', '<', $m->id)->pluck('user_id');
        if (! $from || $unread->isEmpty()) {
            return;
        }
        $preview = $m->kind === 'photo' ? '📷 Photo' : mb_strimwidth((string) $m->body, 0, 80, '…');
        $notifier->send(User::whereIn('id', $unread)->get(), new YutopiaTextNotification($from->id, (string) $from->name, $preview));
    }
}
