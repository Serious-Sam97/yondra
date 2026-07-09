<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\User;
use App\Notifications\DueDateReminderNotification;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendDueDateReminders extends Command
{
    protected $signature = 'notifications:due-reminders';

    protected $description = 'Notify assignees of cards that are due within 24 hours (or overdue).';

    public function handle(Notifier $notifier): int
    {
        $today = Carbon::today();
        $tomorrow = $today->copy()->addDay();

        // Cards due today or tomorrow (or overdue), still open, assigned, and not
        // yet reminded for this due window.
        $cards = Card::query()
            ->whereNotNull('due_date')
            ->whereNotNull('assigned_user_id')
            ->whereNull('archived_at')
            ->whereNull('done_at')
            ->whereNull('due_reminder_sent_at')
            ->whereDate('due_date', '<=', $tomorrow)
            ->get();

        $sent = 0;
        foreach ($cards as $card) {
            $assignee = User::find($card->assigned_user_id);
            if (! $assignee) {
                continue;
            }

            $due = Carbon::parse($card->due_date)->startOfDay();
            $when = $due->lt($today) ? 'overdue' : ($due->eq($today) ? 'today' : 'tomorrow');

            $notifier->send($assignee, new DueDateReminderNotification(
                boardId: (int) $card->board_id,
                cardId: (int) $card->id,
                cardName: (string) $card->name,
                when: $when,
            ));

            $card->update(['due_reminder_sent_at' => now()]);
            $sent++;
        }

        $this->info("Sent {$sent} due-date reminder(s).");

        return self::SUCCESS;
    }
}
