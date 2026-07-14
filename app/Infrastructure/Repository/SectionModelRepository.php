<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\SectionRepository;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\WhatsappReengagementPolicy;
use Illuminate\Support\Facades\DB;

class SectionModelRepository implements SectionRepository
{
    public function index()
    {
        return Section::all();
    }

    public function save($request)
    {
        $nextOrder = Section::where('board_id', $request['board_id'])->max('order') + 1;

        return Section::create([
            'board_id' => $request['board_id'],
            'name' => $request['name'],
            'order' => $nextOrder,
        ]);
    }

    public function update($request)
    {
        $section = Section::where('board_id', $request['board_id'])->findOrFail($request['id']);
        $section->update([
            'name' => $request['name'] ?? $section->name,
            'aging_hours' => array_key_exists('aging_hours', $request)
                ? $request['aging_hours']
                : $section->aging_hours,
        ]);

        return $section;
    }

    public function delete($request)
    {
        $section = Section::where('board_id', $request['board_id'])->findOrFail($request['id']);
        DB::transaction(function () use ($section) {
            // Clear the board's done column if it pointed at this section (no DB FK to do it).
            Board::where('id', $section->board_id)
                ->where('done_section_id', $section->id)
                ->update(['done_section_id' => null]);
            // Same for the re-engagement policy's Lost stage (also no DB FK).
            WhatsappReengagementPolicy::where('lost_section_id', $section->id)
                ->update(['lost_section_id' => null]);
            // Archive the section's cards instead of hard-deleting them — card destroy()
            // archives with a restore endpoint, and section delete must not be the one
            // irreversible path. Cards are detached from the dead section (NULL) so
            // nothing keeps pointing at a deleted row; CardController::restore re-homes
            // them into the board's first section when they come back.
            Card::where('section_id', $section->id)
                ->whereNull('archived_at')
                ->update(['archived_at' => now(), 'section_id' => null]);
            // Already-archived cards keep their archived_at but lose the dead pointer.
            Card::where('section_id', $section->id)
                ->update(['section_id' => null]);
            $section->delete();
        });
    }

    public function reorder(int $boardId, array $sectionIds): void
    {
        DB::transaction(function () use ($boardId, $sectionIds) {
            foreach ($sectionIds as $order => $id) {
                Section::where('board_id', $boardId)->where('id', $id)->update(['order' => $order]);
            }
        });
    }
}
