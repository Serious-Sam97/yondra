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
            ->whereNull('archived_at')
            ->with(['owner:id,name', 'sharedWith:id,name'])
            ->withCount('cards')
            ->get();

        $shared = Board::whereHas('sharedWith', fn($q) => $q->where('users.id', $user->id))
            ->whereNull('archived_at')
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
            'cards.links',
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
        // GitHub: expose whether a token is set (never the token itself). The webhook
        // secret is only useful to — and only shown to — managers setting up the hook.
        $board->github_connected = filled($board->github_token);
        if (!$board->can_manage) {
            $board->github_webhook_secret = null;
        }
        $board->makeHidden('github_token');
        return $board;
    }

    public function save($request) {
        $user = Auth::user();
        $projectId = $request['project_id'] ?? null;
        // New boards inherit their project's default share permission, if set.
        $defaultPermission = $projectId
            ? (\App\Infrastructure\Models\Project::whereKey($projectId)->value('default_permission') ?? 'write')
            : 'write';
        $board = Board::create([
            'user_id'            => $user->id,
            'project_id'         => $projectId,
            'name'               => $request['name'],
            'description'        => $request['description'] ?? '',
            'default_permission' => $defaultPermission,
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
            'name'               => $request['name']        ?? $board->name,
            'description'        => $request['description'] ?? $board->description,
            'project_id'         => array_key_exists('project_id', $request)
                                ? $request['project_id']
                                : $board->project_id,
            'ticket_prefix'      => array_key_exists('ticket_prefix', $request)
                                ? $request['ticket_prefix']
                                : $board->ticket_prefix,
            'next_ticket_number' => $request['next_ticket_number'] ?? $board->next_ticket_number,
            'background'         => array_key_exists('background', $request)
                                ? $request['background']
                                : $board->background,
            'default_permission' => $request['default_permission'] ?? $board->default_permission,
            'github_repo'        => array_key_exists('github_repo', $request)
                                ? $request['github_repo']
                                : $board->github_repo,
        ]);

        // Token is optional on every save; only overwrite when a non-empty value is
        // sent (blank string clears it). Generate a webhook secret on first connect.
        if (array_key_exists('github_token', $request)) {
            $token = $request['github_token'];
            $board->github_token = $token !== '' ? $token : null;
            if ($board->github_token && !$board->github_webhook_secret) {
                $board->github_webhook_secret = bin2hex(random_bytes(20));
            }
            $board->save();
        }

        $fresh = $board->fresh()->load(['owner:id,name', 'sharedWith:id,name'])->loadCount('cards');
        $fresh->github_connected = filled($fresh->github_token);
        $fresh->makeHidden('github_token');
        return $fresh;
    }

    public function setArchived(int $id, bool $archived) {
        $board = Board::findOrFail($id);
        $this->authorizeOwner($board);
        $board->update(['archived_at' => $archived ? now() : null]);
        return $board->fresh()->load(['owner:id,name', 'sharedWith:id,name'])->loadCount('cards');
    }

    public function duplicate(int $id, ?string $name, bool $includeCards) {
        $source = Board::with(['sections', 'tags'])->findOrFail($id);
        $this->authorizeAccess($source);

        $copy = Board::create([
            'user_id'            => Auth::id(),
            'project_id'         => $source->project_id,
            'name'               => $name ?: ($source->name . ' (copy)'),
            'description'        => $source->description,
            'ticket_prefix'      => $source->ticket_prefix,
            'background'         => $source->background,
            'default_permission' => $source->default_permission,
        ]);

        // Clone columns and tags, keeping a map from source id -> new id so cards
        // (if included) can be rehomed onto the cloned structure.
        $sectionMap = [];
        foreach ($source->sections as $section) {
            $new = Section::create(['board_id' => $copy->id, 'name' => $section->name, 'order' => $section->order]);
            $sectionMap[$section->id] = $new->id;
        }

        $tagMap = [];
        foreach ($source->tags as $tag) {
            $new = \App\Infrastructure\Models\Tag::create(['board_id' => $copy->id, 'name' => $tag->name, 'color' => $tag->color]);
            $tagMap[$tag->id] = $new->id;
        }

        if ($includeCards) {
            $cards = Card::where('board_id', $source->id)
                ->whereNull('archived_at')
                ->whereNull('parent_card_id')
                ->with('tags:id')
                ->orderBy('position')
                ->get();
            $ticket = 1;
            foreach ($cards as $card) {
                $clone = $card->replicate(['ticket_number', 'archived_at', 'done_at']);
                $clone->board_id      = $copy->id;
                $clone->section_id    = $sectionMap[$card->section_id] ?? $copy->sections()->min('id');
                $clone->ticket_number = $ticket++;
                $clone->save();
                $newTagIds = $card->tags->pluck('id')->map(fn($tid) => $tagMap[$tid] ?? null)->filter()->all();
                if ($newTagIds) $clone->tags()->sync($newTagIds);
            }
            $copy->update(['next_ticket_number' => $ticket]);
        }

        return $copy->fresh()->load(['owner:id,name', 'sharedWith:id,name'])->loadCount('cards');
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
