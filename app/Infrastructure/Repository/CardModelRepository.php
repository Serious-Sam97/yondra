<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\CardRepository;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use Illuminate\Support\Facades\Auth;

class CardModelRepository implements CardRepository
{
    public function index()
    {
        return Card::all();
    }

    public function save($request)
    {
        $position = Card::where('section_id', $request['section_id'])->max('position') + 1;
        $card = Card::create([
            'board_id'           => $request['board_id'],
            'section_id'         => $request['section_id'],
            'assigned_user_id'   => $request['assigned_user_id'] ?? null,
            'created_by_user_id' => Auth::id(),
            'name'               => $request['name'],
            'description'        => $request['description'] ?? '',
            'due_date'           => $request['due_date'] ?? null,
            'priority'           => $request['priority'] ?? null,
            'position'           => $position,
        ]);

        if (!empty($request['tag_ids'])) {
            $card->tags()->sync($request['tag_ids']);
        }

        return $card->load(['assignedUser:id,name', 'createdBy:id,name', 'tags'])->toArray();
    }

    public function update($request)
    {
        $card = Card::where('board_id', $request['board_id'])->findOrFail($request['id']);

        $newSectionId = $request['section_id'] ?? $card->section_id;
        $doneAt = $card->done_at;

        if (isset($request['section_id']) && $request['section_id'] !== $card->section_id) {
            $section = Section::find($newSectionId);
            if ($section && strtolower($section->name) === 'done') {
                $doneAt = $doneAt ?? now();
            } else {
                $doneAt = null;
            }
        }

        $card->update([
            'section_id'       => $newSectionId,
            'assigned_user_id' => array_key_exists('assigned_user_id', $request)
                ? $request['assigned_user_id']
                : $card->assigned_user_id,
            'name'             => $request['name'] ?? $card->name,
            'description'      => $request['description'] ?? $card->description,
            'due_date'         => array_key_exists('due_date', $request) ? $request['due_date'] : $card->due_date,
            'priority'         => array_key_exists('priority', $request) ? $request['priority'] : $card->priority,
            'position'         => $request['position'] ?? $card->position,
            'done_at'          => $doneAt,
        ]);

        if (array_key_exists('tag_ids', $request)) {
            $card->tags()->sync($request['tag_ids'] ?? []);
        }

        return $card->fresh()->load(['assignedUser:id,name', 'createdBy:id,name', 'tags'])->toArray();
    }

    public function delete($request)
    {
        Card::findOrFail($request['id'])->delete();
    }
}
