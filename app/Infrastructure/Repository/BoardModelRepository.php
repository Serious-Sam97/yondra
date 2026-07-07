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

        $flattenPermissions = fn($boards) => $boards->each(
            fn($b) => $b->sharedWith->each(fn($u) => $u->permission = $u->pivot->permission ?? 'write')
        );

        $flattenPermissions($owned);
        $flattenPermissions($shared);

        return ['owned' => $owned->values(), 'shared' => $shared->values()];
    }

    public function show($id) {
        $board = Board::with([
            'sections',
            'cards' => fn($q) => $q->whereNull('archived_at')->whereNull('parent_card_id')->orderBy('position'),
            'cards.assignedUser:id,name',
            'cards.createdBy:id,name',
            'cards.tags',
            'cards.checklistItems',
            'cards.images',
            'tags',
            'sharedWith:id,name,email',
            'owner:id,name,email',
        ])->findOrFail($id);
        $this->authorizeAccess($board);
        // Attach the human-facing ticket key (YON-42 / #42) to each card.
        $board->cards->each(fn($c) => $c->ticket_key = CardModelRepository::composeTicketKey($board->ticket_prefix, $c->ticket_number));
        $board->sharedWith->each(fn($u) => $u->permission = $u->pivot->permission ?? 'write');
        // Expose the current user's capabilities so the client can gate editing/managing
        // without re-deriving the (project-aware) permission rules.
        $uid = Auth::id();
        $board->can_write  = $board->isWritableBy($uid);
        $board->can_manage = $board->isOwnedBy($uid);
        return $board;
    }

    public function save($request) {
        $user = Auth::user();
        $board = Board::create([
            'user_id'     => $user->id,
            'project_id'  => $request['project_id'] ?? null,
            'name'        => $request['name'],
            'description' => $request['description'] ?? '',
        ]);

        foreach (['To Do', 'In Progress', 'Done'] as $i => $sectionName) {
            Section::create(['board_id' => $board->id, 'name' => $sectionName, 'order' => $i]);
        }

        return $board->load('sections');
    }

    public function update($request) {
        $board = Board::findOrFail($request['id']);
        $this->authorizeOwner($board);

        $board->update([
            'name'          => $request['name']        ?? $board->name,
            'description'   => $request['description'] ?? $board->description,
            'project_id'    => array_key_exists('project_id', $request)
                                ? $request['project_id']
                                : $board->project_id,
            'ticket_prefix' => array_key_exists('ticket_prefix', $request)
                                ? $request['ticket_prefix']
                                : $board->ticket_prefix,
        ]);

        return $board->fresh()->load(['owner:id,name', 'sharedWith:id,name'])->loadCount('cards');
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
        if (!$board->isOwnedBy(Auth::id())) {
            throw new AccessDeniedHttpException();
        }
    }
}
