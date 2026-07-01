<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\SectionRepository;
use App\Infrastructure\Models\Section;
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
            'name'     => $request['name'],
            'order'    => $nextOrder,
        ]);
    }

    public function update($request)
    {
        $section = Section::where('board_id', $request['board_id'])->findOrFail($request['id']);
        $section->update(['name' => $request['name']]);
        return $section;
    }

    public function delete($request)
    {
        $section = Section::where('board_id', $request['board_id'])->findOrFail($request['id']);
        \App\Infrastructure\Models\Card::where('section_id', $section->id)->delete();
        $section->delete();
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
