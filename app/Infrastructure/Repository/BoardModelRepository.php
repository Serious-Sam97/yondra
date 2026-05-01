<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\BoardRepository;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BoardModelRepository implements BoardRepository {
    public function index() {
        $user = Auth::user();

        $owned = Board::where('user_id', $user->id)
            ->with(['owner:id,name', 'sharedWith:id,name'])
            ->withCount('cards')
            ->get();

        $shared = Board::whereHas('sharedWith', fn($q) => $q->where('users.id', $user->id))
            ->with(['owner:id,name', 'sharedWith:id,name'])
            ->withCount('cards')
            ->get();

        return ['owned' => $owned->values(), 'shared' => $shared->values()];
    }

    public function show($id) {
        $board = Board::with([
            'sections',
            'cards' => fn($q) => $q->whereNull('archived_at')->orderBy('position'),
            'cards.assignedUser:id,name',
            'cards.createdBy:id,name',
            'cards.tags',
            'cards.checklistItems',
            'tags',
            'sharedWith:id,name,email',
            'owner:id,name,email',
        ])->findOrFail($id);
        $this->authorizeAccess($board);
        return $board;
    }

    public function save($request) {
        $user = Auth::user();
        $board = Board::create([
            'user_id'     => $user->id,
            'name'        => $request['name'],
            'description' => $request['description'] ?? '',
        ]);

        foreach (['To Do', 'In Progress', 'Done'] as $sectionName) {
            Section::create(['board_id' => $board->id, 'name' => $sectionName]);
        }

        return $board->load('sections');
    }

    public function update($request) {
        // TODO: Implement update method
    }

    public function delete($request) {
        $board = Board::findOrFail($request['id']);
        $this->authorizeOwner($board);

        Card::where('board_id', $board->id)->delete();
        Section::where('board_id', $board->id)->delete();
        $board->delete();
    }

    private function authorizeAccess(Board $board): void {
        if (!$board->isAccessibleBy(Auth::id())) {
            throw new AccessDeniedHttpException();
        }
    }

    private function authorizeOwner(Board $board): void {
        if ($board->user_id !== Auth::id()) {
            throw new AccessDeniedHttpException();
        }
    }
}
