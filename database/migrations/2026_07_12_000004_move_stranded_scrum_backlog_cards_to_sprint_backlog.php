<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Recover scrum tickets stranded in the reserved "Backlog" section.
     *
     * The reserved section named "Backlog" is a non-scrum mechanism: it is filtered
     * out of every scrum board view. When a card was "Sent to Backlog" from the card
     * editor on a scrum board, it was moved into that section and became invisible in
     * BOTH the kanban (active-sprint only) and the sprint-planning backlog (fed from
     * board cards, which excludes the reserved section).
     *
     * On scrum boards the real backlog is the null-sprint pool. This migration moves
     * any card stuck in a scrum board's "Backlog" section back into a real column with
     * sprint_id = NULL, so it reappears in the planning view's BACKLOG group.
     */
    public function up(): void
    {
        // Reserved backlog sections that belong to a scrum board.
        $backlogSections = DB::table('sections')
            ->join('boards', 'sections.board_id', '=', 'boards.id')
            ->where('sections.name', 'Backlog')
            ->where('boards.type', 'scrum')
            ->select('sections.id', 'sections.board_id')
            ->get();

        foreach ($backlogSections as $backlog) {
            // Leftmost real column on this board to drop the tickets into.
            $target = DB::table('sections')
                ->where('board_id', $backlog->board_id)
                ->where('id', '!=', $backlog->id)
                ->where('name', '!=', 'Backlog')
                ->orderBy('order')
                ->orderBy('id')
                ->first();

            // No real column to land in — nothing safe to do; leave the cards put.
            if (! $target) {
                continue;
            }

            $stranded = DB::table('cards')
                ->where('section_id', $backlog->id)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id');

            if ($stranded->isEmpty()) {
                continue;
            }

            // Append after whatever already lives in the target column.
            $position = (int) DB::table('cards')
                ->where('section_id', $target->id)
                ->max('position');

            foreach ($stranded as $cardId) {
                $position++;
                DB::table('cards')
                    ->where('id', $cardId)
                    ->update([
                        'section_id' => $target->id,
                        'sprint_id' => null,
                        'position' => $position,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Irreversible: the cards' original section is not recorded, and after recovery
     * they are indistinguishable from any other null-sprint backlog ticket.
     */
    public function down(): void
    {
        // No-op — data recovery cannot be safely reversed.
    }
};
