<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\CardRepository;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CardModelRepository implements CardRepository
{
    public function index()
    {
        return Card::all();
    }

    public function save($request)
    {
        return DB::transaction(function () use ($request) {
            // Lock the board row so concurrent creates each get a distinct number.
            $board = Board::whereKey($request['board_id'])->lockForUpdate()->firstOrFail();
            $ticketNumber = $board->next_ticket_number;
            $board->increment('next_ticket_number');

            $position = Card::where('section_id', $request['section_id'])->max('position') + 1;
            $card = Card::create([
                'board_id' => $request['board_id'],
                'section_id' => $request['section_id'],
                // Subtasks flow through the same create path so they inherit real
                // ticket numbers, section-scoped positions, and SLA stamping.
                'parent_card_id' => $request['parent_card_id'] ?? null,
                'assigned_user_id' => $request['assigned_user_id'] ?? null,
                'created_by_user_id' => Auth::id(),
                'name' => $request['name'],
                'description' => $request['description'] ?? '',
                'due_date' => $request['due_date'] ?? null,
                'priority' => $request['priority'] ?? null,
                'position' => $position,
                'ticket_number' => $ticketNumber,
                'value' => $request['value'] ?? null,
                'story_points' => $request['story_points'] ?? null,
                'sprint_id' => $request['sprint_id'] ?? null,
                // Stamp stage entry so per-stage SLA aging measures from creation.
                'section_entered_at' => now(),
            ]);

            if (! empty($request['tag_ids'])) {
                $card->tags()->sync($request['tag_ids']);
            }

            return $card->load(['assignedUser:id,name', 'createdBy:id,name', 'tags', 'images', 'links', 'documents']);
        });
    }

    public function update($request)
    {
        $card = Card::where('board_id', $request['board_id'])->findOrFail($request['id']);

        $newSectionId = $request['section_id'] ?? $card->section_id;
        $doneAt = $card->done_at;
        $sectionEnteredAt = $card->section_entered_at;
        $sectionChanged = isset($request['section_id']) && $request['section_id'] !== $card->section_id;

        if ($sectionChanged) {
            $section = Section::find($newSectionId);
            $board = Board::find($card->board_id);
            if ($section && $board && $board->marksDone($section)) {
                $doneAt = $doneAt ?? now();
            } else {
                $doneAt = null;
            }
            // Reset the SLA-aging clock: moving stage means the card is fresh again.
            $sectionEnteredAt = now();
        }

        $card->update([
            'section_id' => $newSectionId,
            'assigned_user_id' => array_key_exists('assigned_user_id', $request)
                ? $request['assigned_user_id']
                : $card->assigned_user_id,
            'name' => $request['name'] ?? $card->name,
            'description' => $request['description'] ?? $card->description,
            'due_date' => array_key_exists('due_date', $request) ? $request['due_date'] : $card->due_date,
            'priority' => array_key_exists('priority', $request) ? $request['priority'] : $card->priority,
            'position' => $request['position'] ?? $card->position,
            'value' => array_key_exists('value', $request) ? $request['value'] : $card->value,
            'story_points' => array_key_exists('story_points', $request) ? $request['story_points'] : $card->story_points,
            'sprint_id' => array_key_exists('sprint_id', $request) ? $request['sprint_id'] : $card->sprint_id,
            'done_at' => $doneAt,
            'section_entered_at' => $sectionEnteredAt,
        ]);

        if (array_key_exists('tag_ids', $request)) {
            $card->tags()->sync($request['tag_ids'] ?? []);
        }

        // ticket_key is appended by CardResource at the HTTP boundary.
        return $card->fresh()->load(['assignedUser:id,name', 'createdBy:id,name', 'tags', 'images', 'links', 'documents']);
    }

    public function delete($request)
    {
        Card::findOrFail($request['id'])->delete();
    }
}
