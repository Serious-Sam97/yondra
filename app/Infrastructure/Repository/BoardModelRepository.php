<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\BoardRepository;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Tag;
use App\Services\TagService;
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

        return ['owned' => $owned->values(), 'shared' => $shared->values()];
    }

    public function show($id, bool $includeSubtasks = false)
    {
        $board = Board::with([
            'sections',
            'sprints',
            // Top-level cards carry subtask rollup counts (epic progress chip). When
            // $includeSubtasks, child cards load too (for the board "Show subtasks" toggle).
            'cards' => fn ($q) => $q->whereNull('archived_at')
                ->when(! $includeSubtasks, fn ($qq) => $qq->whereNull('parent_card_id'))
                ->withCount([
                    'subtasks as subtasks_count' => fn ($sq) => $sq->whereNull('archived_at'),
                    'subtasks as done_subtasks_count' => fn ($sq) => $sq->whereNull('archived_at')
                        ->where(fn ($w) => $w->whereNotNull('done_at')->orWhere('is_done', true)),
                ])
                ->orderBy('position'),
            'cards.assignedUser:id,name',
            'cards.contact',
            'cards.createdBy:id,name',
            'cards.tags',
            'cards.checklistItems',
            'cards.images',
            'cards.links',
            'cards.documents',
            'tags',
            'sharedWith:id,name,email',
            'owner:id,name,email',
        ])->findOrFail($id);
        $this->authorizeAccess($board);

        // Serialization (ticket keys, capabilities, connected flags, secret
        // redaction) lives in BoardResource — the repository returns the model.
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
        // funnel (ending in Won + a Lost stage); kanban/scrum start with the
        // classic workflow lanes.
        $defaultSections = $type === 'crm'
            ? ['Lead In', 'Contact Made', 'Proposal Made', 'Negotiations Started', 'Won', 'Lost']
            : ['To Do', 'In Progress', 'Done'];
        foreach ($defaultSections as $i => $sectionName) {
            Section::create(['board_id' => $board->id, 'name' => $sectionName, 'order' => $i]);
        }

        // CRM boards get a designated Lost stage + an editable default reason list,
        // so "required loss reason on lost" (YON-66) works out of the box.
        if ($type === 'crm') {
            $lostId = Section::where('board_id', $board->id)->where('name', 'Lost')->value('id');
            $board->update([
                'lost_section_id' => $lostId,
                'loss_reasons' => Board::DEFAULT_LOSS_REASONS,
            ]);
        }

        // Seed the canonical channel tags (WhatsApp/Email/Phone/Instagram) so
        // the board's Channel/Custom tag split is populated from day one.
        resolve(TagService::class)->seedChannelTags($board->id);

        return $board->load('sections');
    }

    public function update($request)
    {
        $board = Board::findOrFail($request['id']);
        $this->authorizeOwner($board);

        // Moving to a different project appends the board to the end of that
        // project's ordered board list (YON-125); a project-less board resets to 0.
        $targetPosition = $board->position;
        if (array_key_exists('project_id', $request) && $request['project_id'] !== $board->project_id) {
            $targetPosition = $request['project_id'] === null
                ? 0
                : (int) Board::where('project_id', $request['project_id'])->max('position') + 1;
        }

        $board->update([
            'name' => $request['name'] ?? $board->name,
            'type' => $request['type'] ?? $board->type,
            'currency' => $request['currency'] ?? $board->currency,
            'done_section_id' => array_key_exists('done_section_id', $request)
                                ? $request['done_section_id']
                                : $board->done_section_id,
            'lost_section_id' => array_key_exists('lost_section_id', $request)
                                ? $request['lost_section_id']
                                : $board->lost_section_id,
            'loss_reasons' => array_key_exists('loss_reasons', $request)
                                ? $request['loss_reasons']
                                : $board->loss_reasons,
            'qa_enabled' => array_key_exists('qa_enabled', $request)
                                ? $request['qa_enabled']
                                : $board->qa_enabled,
            'description' => $request['description'] ?? $board->description,
            'project_id' => array_key_exists('project_id', $request)
                                ? $request['project_id']
                                : $board->project_id,
            'position' => $targetPosition,
            'ticket_prefix' => array_key_exists('ticket_prefix', $request)
                                ? $request['ticket_prefix']
                                : $board->ticket_prefix,
            'next_ticket_number' => $request['next_ticket_number'] ?? $board->next_ticket_number,
            'background' => array_key_exists('background', $request)
                                ? $request['background']
                                : $board->background,
            'default_permission' => $request['default_permission'] ?? $board->default_permission,
            'intake_field_map' => array_key_exists('intake_field_map', $request)
                                ? $request['intake_field_map']
                                : $board->intake_field_map,
            'email_spam_safe' => array_key_exists('email_spam_safe', $request)
                                ? $request['email_spam_safe']
                                : $board->email_spam_safe,
            'require_optin_before_email' => array_key_exists('require_optin_before_email', $request)
                                ? $request['require_optin_before_email']
                                : $board->require_optin_before_email,
            'invoice_issuer' => array_key_exists('invoice_issuer', $request)
                                ? $request['invoice_issuer']
                                : $board->invoice_issuer,
            'roadmap_config' => array_key_exists('roadmap_config', $request)
                                ? $request['roadmap_config']
                                : $board->roadmap_config,
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

        // Intake webhook: enabling mints an unguessable token (once), disabling clears it.
        if (array_key_exists('intake_enabled', $request)) {
            if ($request['intake_enabled']) {
                $board->intake_token = $board->intake_token ?: bin2hex(random_bytes(24));
            } else {
                $board->intake_token = null;
            }
        }
        $board->save();

        return $board->fresh()->load(['owner:id,name', 'sharedWith:id,name'])->loadCount('cards');
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
            $new = Tag::create(['board_id' => $copy->id, 'name' => $tag->name, 'color' => $tag->color, 'kind' => $tag->kind]);
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
