<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\SectionRepository;
use App\Infrastructure\Models\Section;

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
        $section = Section::findOrFail($request['id']);
        $section->update(['name' => $request['name']]);
        return $section;
    }

    public function delete($request)
    {
        $section = Section::findOrFail($request['id']);
        \App\Infrastructure\Models\Card::where('section_id', $section->id)->delete();
        $section->delete();
    }

    public function reorder(array $sectionIds): void
    {
        foreach ($sectionIds as $order => $id) {
            Section::where('id', $id)->update(['order' => $order]);
        }
    }
}
