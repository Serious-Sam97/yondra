<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\CardRepository;
use App\Infrastructure\Models\Card;

class CardModelRepository implements CardRepository
{
    public function index()
    {
        return Card::all();
    }

    public function save($request)
    {
        $card = Card::create([
            'board_id'    => $request['board_id'],
            'section_id'  => $request['section_id'],
            'name'        => $request['name'],
            'description' => $request['description'] ?? '',
        ]);

        return $card->toArray();
    }

    public function update($request)
    {
        $card = Card::where('board_id', $request['board_id'])->findOrFail($request['id']);

        $card->update([
            'section_id'  => $request['section_id'] ?? $card->section_id,
            'name'        => $request['name'] ?? $card->name,
            'description' => $request['description'] ?? $card->description,
        ]);

        return $card->fresh()->toArray();
    }

    public function delete($request)
    {
        Card::findOrFail($request['id'])->delete();
    }
}
