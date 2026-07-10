<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\BoardRepository;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BoardModelRepository implements BoardRepository
{
    public function index()
    {
        $user = Auth::user();

        $owned = Board::where('user_id', $user->id)
            ->whereNull('archived_at')
            ->with(['owner:id,name', 'sharedWith:id,name'])
            ->withCount('cards')
            ->get();

        $shared = Board::whereHas('sharedWith', fn ($q) => $q->where('users.id', $user->id))
            ->whereNull('archived_at')
            ->with(['owner:id,name', 'sharedWith:id,name'])
            ->withCount('cards')
            ->get();

        $flattenPermissions = fn ($boards) => $boards->each(
            fn ($b) => $b->sharedWith->each(fn ($u) => $u->permission = $u->pivot->permission ?? 'write')
        );

        $flattenPermissions($owned);
        $flattenPermissions($shared);

        return ['owned' => $owned->values(), 'shared' => $shared->values()];
    }

    public function show($id)
    {
        $board = Board::with([
            'sections',
            'sprints',
            'cards' => fn ($q) => $q->whereNull('archived_at')->whereNull('parent_card_id')->orderBy('position'),
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
        $board->cards->each(fn ($c) => $c->ticket_key = CardModelRepository::composeTicketKey($board->ticket_prefix, $c->ticket_number));
        $board->sharedWith->each(fn ($u) => $u->permission = $u->pivot->permission ?? 'write');
        // Expose the current user's capabilities so the client can gate editing/managing
        // without re-deriving the (project-aware) permission rules.
        $uid = Auth::id();
        $board->can_write = $board->isWritableBy($uid);
        $board->can_manage = $board->isOwnedBy($uid);
        // GitHub: expose whether a token is set (never the token itself). The webhook
        // secret is only useful to — and only shown to — managers setting up the hook.
        $board->github_connected = filled($board->github_token);
        if (! $board->can_manage) {
            $board->github_webhook_secret = null;
        }
        $board->makeHidden('github_token');

        // WhatsApp: expose connected state, hide the secrets. The verify token is
        // needed to configure the Meta webhook, so it's shown to managers only.
        $board->whatsapp_connected = filled($board->whatsapp_token);
        if (! $board->can_manage) {
            $board->whatsapp_verify_token = null;
        }
        $board->makeHidden(['whatsapp_token', 'whatsapp_app_secret']);

        return $board;
    }

    public function save($request)
    {
        $user = Auth::user();
        $projectId = $request['project_id'] ?? null;
        // New boards inherit their project's default share permission, if set.
        $defaultPermission = $projectId
            ? (Project::whereKey($projectId)->value('default_permission') ?? 'write')
            : 'write';
        $type = $request['type'] ?? 'kanban';
        $board = Board::create([
            'user_id' => $user->id,
            'project_id' => $projectId,
            'name' => $request['name'],
            'type' => $type,
            'currency' => $request['currency'] ?? 'BRL',
            'description' => $request['description'] ?? '',
            'default_permission' => $defaultPermission,
            // Sentinel (QA) is on by default for every new board.
            'qa_enabled' => true,
        ]);

        // Seed columns that fit the board type. CRM boards start as a sales
        // funnel; kanban/scrum start with the classic workflow lanes.
        $defaultSections = $type === 'crm'
            ? ['Lead In', 'Contact Made', 'Proposal Made', 'Negotiations Started', 'Won']
            : ['To Do', 'In Progress', 'Done'];
        foreach ($defaultSections as $i => $sectionName) {
            Section::create(['board_id' => $board->id, 'name' => $sectionName, 'order' => $i]);
        }

        return $board->load('sections');
    }

    public function update($request)
    {
        $board = Board::findOrFail($request['id']);
        $this->authorizeOwner($board);

        $board->update([
            'name' => $request['name'] ?? $board->name,
            'type' => $request['type'] ?? $board->type,
            'currency' => $request['currency'] ?? $board->currency,
            'done_section_id' => array_key_exists('done_section_id', $request)
                                ? $request['done_section_id']
                                : $board->done_section_id,
            'qa_enabled' => array_key_exists('qa_enabled', $request)
                                ? $request['qa_enabled']
                                : $board->qa_enabled,
            'description' => $request['description'] ?? $board->description,
            'project_id' => array_key_exists('project_id', $request)
                                ? $request['project_id']
                                : $board->project_id,
            'ticket_prefix' => array_key_exists('ticket_prefix', $request)
                                ? $request['ticket_prefix']
                                : $board->ticket_prefix,
            'next_ticket_number' => $request['next_ticket_number'] ?? $board->next_ticket_number,
            'background' => array_key_exists('background', $request)
                                ? $request['background']
                                : $board->background,
            'default_permission' => $request['default_permission'] ?? $board->default_permission,
            'github_repo' => array_key_exists('github_repo', $request)
                                ? $request['github_repo']
                                : $board->github_repo,
            'whatsapp_provider' => array_key_exists('whatsapp_provider', $request)
                                ? $request['whatsapp_provider']
                                : $board->whatsapp_provider,
            'whatsapp_phone_number_id' => array_key_exists('whatsapp_phone_number_id', $request)
                                ? $request['whatsapp_phone_number_id']
                                : $board->whatsapp_phone_number_id,
            'whatsapp_waba_id' => array_key_exists('whatsapp_waba_id', $request)
                                ? $request['whatsapp_waba_id']
                                : $board->whatsapp_waba_id,
            'whatsapp_verify_token' => array_key_exists('whatsapp_verify_token', $request)
                                ? ($request['whatsapp_verify_token'] ?: null)
                                : $board->whatsapp_verify_token,
        ]);

        // Token is optional on every save; only overwrite when a non-empty value is
        // sent (blank string clears it). Generate a webhook secret on first connect.
        if (array_key_exists('github_token', $request)) {
            $token = $request['github_token'];
            $board->github_token = $token !== '' ? $token : null;
            if ($board->github_token && ! $board->github_webhook_secret) {
                $board->github_webhook_secret = bin2hex(random_bytes(20));
            }
            $board->save();
        }

        // WhatsApp secrets follow the same rule: overwrite only on a non-empty value.
        foreach (['whatsapp_token', 'whatsapp_app_secret'] as $secret) {
            if (array_key_exists($secret, $request)) {
                $value = $request[$secret];
                $board->{$secret} = ($value !== null && $value !== '') ? $value : null;
            }
        }
        // On first connect, auto-issue a verify token if the manager didn't set one.
        if ($board->whatsapp_token && ! $board->whatsapp_verify_token) {
            $board->whatsapp_verify_token = bin2hex(random_bytes(16));
        }
        $board->save();

        $fresh = $board->fresh()->load(['owner:id,name', 'sharedWith:id,name'])->loadCount('cards');
        $fresh->github_connected = filled($fresh->github_token);
        $fresh->whatsapp_connected = filled($fresh->whatsapp_token);
        $fresh->makeHidden(['github_token', 'whatsapp_token', 'whatsapp_app_secret']);

        return $fresh;
    }

    public function setArchived(int $id, bool $archived)
    {
        $board = Board::findOrFail($id);
        $this->authorizeOwner($board);
        $board->update(['archived_at' => $archived ? now() : null]);

        return $board->fresh()->load(['owner:id,name', 'sharedWith:id,name'])->loadCount('cards');
    }

    public function duplicate(int $id, ?string $name, bool $includeCards)
    {
        $source = Board::with(['sections', 'tags'])->findOrFail($id);
        $this->authorizeAccess($source);

        $copy = Board::create([
            'user_id' => Auth::id(),
            'project_id' => $source->project_id,
            'name' => $name ?: ($source->name.' (copy)'),
            'type' => $source->type,
            'currency' => $source->currency,
            'description' => $source->description,
            'ticket_prefix' => $source->ticket_prefix,
            'background' => $source->background,
            'default_permission' => $source->default_permission,
            'qa_enabled' => $source->qa_enabled,
        ]);

        // Clone columns and tags, keeping a map from source id -> new id so cards
        // (if included) can be rehomed onto the cloned structure.
        $sectionMap = [];
        foreach ($source->sections as $section) {
            $new = Section::create(['board_id' => $copy->id, 'name' => $section->name, 'order' => $section->order, 'aging_hours' => $section->aging_hours]);
            $sectionMap[$section->id] = $new->id;
        }

        $tagMap = [];
        foreach ($source->tags as $tag) {
            $new = Tag::create(['board_id' => $copy->id, 'name' => $tag->name, 'color' => $tag->color]);
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
                $clone->board_id = $copy->id;
                $clone->section_id = $sectionMap[$card->section_id] ?? $copy->sections()->min('id');
                $clone->ticket_number = $ticket++;
                $clone->save();
                $newTagIds = $card->tags->pluck('id')->map(fn ($tid) => $tagMap[$tid] ?? null)->filter()->all();
                if ($newTagIds) {
                    $clone->tags()->sync($newTagIds);
                }
            }
            $copy->update(['next_ticket_number' => $ticket]);
        }

        return $copy->fresh()->load(['owner:id,name', 'sharedWith:id,name'])->loadCount('cards');
    }

    public function delete($request)
    {
        $board = Board::findOrFail($request['id']);
        $this->authorizeOwner($board);

        Card::where('board_id', $board->id)->delete();
        Section::where('board_id', $board->id)->delete();
        $board->delete();
    }

    private function authorizeAccess(Board $board): void
    {
        if (! $board->isAccessibleBy(Auth::id())) {
            throw new AccessDeniedHttpException;
        }
    }

    private function authorizeOwner(Board $board): void
    {
        if (! $board->isOwnedBy(Auth::id())) {
            throw new AccessDeniedHttpException;
        }
    }
}
