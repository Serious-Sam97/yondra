<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Data migration: give every existing top-level card a fresh per-board ticket
// number (1..N by creation order) and set each board's next_ticket_number.
// Subtasks (parent_card_id set) are left null.
return new class extends Migration
{
    public function up(): void
    {
        $boardIds = DB::table('boards')->pluck('id');

        foreach ($boardIds as $boardId) {
            $cards = DB::table('cards')
                ->where('board_id', $boardId)
                ->whereNull('parent_card_id')
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            $n = 0;
            foreach ($cards as $cardId) {
                $n++;
                DB::table('cards')->where('id', $cardId)->update(['ticket_number' => $n]);
            }

            DB::table('boards')->where('id', $boardId)->update(['next_ticket_number' => $n + 1]);
        }
    }

    public function down(): void
    {
        DB::table('cards')->update(['ticket_number' => null]);
        DB::table('boards')->update(['next_ticket_number' => 1]);
    }
};
