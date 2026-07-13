<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Data migration: subtasks are becoming first-class board cards, so give existing
// subtasks (parent_card_id set, ticket_number null) real per-board ticket numbers,
// continuing each board's sequence. Counterpart to 2026_07_07_000003, which
// deliberately left subtasks null. Also backfills done_at from the legacy is_done
// flag so epic rollups (which count done_at) reflect already-completed subtasks.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('boards')->pluck('id') as $boardId) {
            DB::transaction(function () use ($boardId) {
                $board = DB::table('boards')->where('id', $boardId)->lockForUpdate()->first();
                if (! $board) {
                    return;
                }
                $next = (int) $board->next_ticket_number;

                $subtaskIds = DB::table('cards')
                    ->where('board_id', $boardId)
                    ->whereNotNull('parent_card_id')
                    ->whereNull('ticket_number')
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($subtaskIds as $id) {
                    DB::table('cards')->where('id', $id)->update(['ticket_number' => $next]);
                    $next++;
                }

                DB::table('boards')->where('id', $boardId)->update(['next_ticket_number' => $next]);
            });
        }

        // Legacy subtasks completed via the old is_done toggle predate done_at-based
        // rollups; stamp done_at (from updated_at) so they still count as done.
        DB::table('cards')
            ->whereNotNull('parent_card_id')
            ->where('is_done', true)
            ->whereNull('done_at')
            ->update(['done_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // One-way data backfill: ticket numbers and done_at stamps are left in place
        // (there is no safe way to distinguish backfilled rows from newer real data).
    }
};
