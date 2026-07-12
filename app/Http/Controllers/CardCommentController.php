<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\CommentReaction;
use App\Infrastructure\Models\User;
use App\Notifications\CardCommentedNotification;
use App\Notifications\CommentRepliedNotification;
use App\Services\MentionService;
use App\Services\Notifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class CardCommentController extends Controller
{
    /**
     * Newest-first pages of the card's TOP-LEVEL comments (simplePaginate: `data`
     * + `next_page_url`). Replies live behind each comment's thread (see replies())
     * and are summarized here via replies_count / last_reply_at. The UI renders
     * newest at the top and appends older pages below via `?page=N`.
     * `id` tie-breaks same-second timestamps to keep page boundaries stable.
     */
    public function index(int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId);

        $page = CardComment::where('card_id', $card->id)
            ->whereNull('parent_id')
            ->with('user:id,name')
            ->withCount('replies')
            ->withMax('replies as last_reply_at', 'created_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(30);

        $ids = collect($page->items())->pluck('id');
        $reactions = $this->reactionsFor($ids);
        $repliers = $this->repliersFor($ids);

        return $page->through(fn (CardComment $c) => $this->serialize($c, $reactions, $repliers));
    }

    // A thread in conversation order (oldest first) — expanded lazily by the UI.
    public function replies(int $boardId, int $cardId, int $commentId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId);
        CardComment::where('card_id', $card->id)->whereNull('parent_id')->findOrFail($commentId);

        $page = CardComment::where('parent_id', $commentId)
            ->with('user:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->simplePaginate(50);

        $reactions = $this->reactionsFor(collect($page->items())->pluck('id'));

        return $page->through(fn (CardComment $c) => $this->serialize($c, $reactions));
    }

    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId);
        $validated = $request->validate([
            'body' => ['required', 'string'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        // Single-level threads: a reply's parent must be a comment on this card;
        // replying to a reply re-parents onto the thread root.
        $parent = null;
        if (! empty($validated['parent_id'])) {
            $parent = CardComment::where('card_id', $card->id)->findOrFail($validated['parent_id']);
            if ($parent->parent_id !== null) {
                $parent = CardComment::findOrFail($parent->parent_id);
            }
        }

        $comment = CardComment::create([
            'card_id' => $card->id,
            'user_id' => Auth::id(),
            'body' => $validated['body'],
            'parent_id' => $parent?->id,
        ]);
        $comment->load('user:id,name');

        $actor = Auth::user();

        if ($parent) {
            // A reply speaks to the thread: root author + prior repliers (minus me).
            $notified = collect([$parent->user_id])
                ->merge(CardComment::where('parent_id', $parent->id)->pluck('user_id'))
                ->filter()
                ->reject(fn ($id) => $id === $actor->id)
                ->unique()
                ->values();

            if ($notified->isNotEmpty()) {
                resolve(Notifier::class)->send(
                    User::whereIn('id', $notified)->get(),
                    new CommentRepliedNotification(
                        actorId: (int) $actor->id,
                        actorName: $actor->name,
                        boardId: $boardId,
                        cardId: $cardId,
                        cardName: $card->name,
                    ),
                );
            }
        } else {
            // Notify the assignee and the card's creator (deduped, minus the author).
            $notified = collect([$card->assigned_user_id, $card->created_by_user_id])
                ->filter()
                ->reject(fn ($id) => $id === $actor->id)
                ->unique()
                ->values();

            if ($notified->isNotEmpty()) {
                resolve(Notifier::class)->send(
                    User::whereIn('id', $notified)->get(),
                    new CardCommentedNotification(
                        actorId: (int) $actor->id,
                        actorName: $actor->name,
                        boardId: $boardId,
                        cardId: $cardId,
                        cardName: $card->name,
                    ),
                );
            }
        }

        resolve(MentionService::class)->notify(
            $boardId,
            $cardId,
            $validated['body'],
            $actor->name.' mentioned you in "'.$card->name.'"',
            $notified,
        );

        $payload = $this->serialize($comment, $this->reactionsFor(collect([$comment->id])));
        broadcast(new BoardEvent($boardId, 'comment.created', $payload));

        return response()->json($payload, 201);
    }

    public function update(Request $request, int $boardId, int $cardId, int $commentId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId);
        $validated = $request->validate(['body' => ['required', 'string']]);
        $comment = CardComment::where('card_id', $card->id)->where('user_id', Auth::id())->findOrFail($commentId);
        $comment->update(['body' => $validated['body']]);
        $comment->load('user:id,name');

        $payload = $this->serialize($comment, $this->reactionsFor(collect([$comment->id])));
        broadcast(new BoardEvent($boardId, 'comment.updated', $payload));

        return response()->json($payload);
    }

    public function destroy(int $boardId, int $cardId, int $commentId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId);
        $comment = CardComment::where('card_id', $card->id)->where('user_id', Auth::id())->findOrFail($commentId);
        [$id, $parentId] = [$comment->id, $comment->parent_id];
        $comment->delete();

        broadcast(new BoardEvent($boardId, 'comment.deleted', [
            'card_id' => $card->id,
            'comment_id' => $id,
            'parent_id' => $parentId,
        ]));

        return response()->json(null, 204);
    }

    // Toggle the caller's reaction: on if absent, off if present. Any board member
    // may react to any comment. Returns the comment with its fresh aggregate.
    public function react(Request $request, int $boardId, int $cardId, int $commentId)
    {
        $this->authorizeBoard($boardId);
        $card = $this->boardCard($boardId, $cardId);
        $validated = $request->validate(['emoji' => ['required', 'string', 'min:1', 'max:16']]);

        $comment = CardComment::where('card_id', $card->id)->with('user:id,name')->findOrFail($commentId);

        $existing = CommentReaction::where('card_comment_id', $comment->id)
            ->where('user_id', Auth::id())
            ->where('emoji', $validated['emoji'])
            ->first();
        if ($existing) {
            $existing->delete();
        } else {
            try {
                CommentReaction::create([
                    'card_comment_id' => $comment->id,
                    'user_id' => Auth::id(),
                    'emoji' => $validated['emoji'],
                ]);
            } catch (UniqueConstraintViolationException) {
                // A double-click raced itself — the reaction is already on.
            }
        }

        $payload = $this->serialize(
            $comment->loadCount('replies'),
            $this->reactionsFor(collect([$comment->id])),
        );
        broadcast(new BoardEvent($boardId, 'comment.reacted', $payload));

        return response()->json($payload);
    }

    // --- helpers ---

    /**
     * Reaction aggregates for a set of comment ids, one query:
     * comment_id => [{emoji, count, user_ids, names}]. `mine` is derived client-side
     * from user_ids so the same payload works for HTTP responses AND broadcasts.
     */
    private function reactionsFor(Collection $commentIds): Collection
    {
        if ($commentIds->isEmpty()) {
            return collect();
        }

        return CommentReaction::whereIn('card_comment_id', $commentIds)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy('card_comment_id')
            ->map(fn (Collection $group) => $group
                ->groupBy('emoji')
                ->map(fn (Collection $rows, string $emoji) => [
                    'emoji' => $emoji,
                    'count' => $rows->count(),
                    'user_ids' => $rows->pluck('user_id')->values()->all(),
                    'names' => $rows->map(fn (CommentReaction $r) => $r->user?->name ?? 'Unknown')->values()->all(),
                ])
                ->values()
                ->all());
    }

    /**
     * Up to 3 most-recent distinct repliers per top-level comment, one query —
     * feeds the collapsed thread's stacked face preview. parent_id => [{id,name}].
     */
    private function repliersFor(Collection $parentIds): Collection
    {
        if ($parentIds->isEmpty()) {
            return collect();
        }

        return CardComment::whereIn('parent_id', $parentIds)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get(['id', 'parent_id', 'user_id'])
            ->groupBy('parent_id')
            ->map(fn (Collection $group) => $group
                ->unique('user_id')
                ->take(3)
                ->map(fn (CardComment $r) => ['id' => $r->user_id, 'name' => $r->user?->name ?? 'Unknown'])
                ->values()
                ->all());
    }

    private function serialize(CardComment $c, Collection $reactions, ?Collection $repliers = null): array
    {
        return [
            'id' => $c->id,
            'card_id' => $c->card_id,
            'parent_id' => $c->parent_id,
            'body' => $c->body,
            'user' => ['id' => $c->user?->id ?? 0, 'name' => $c->user?->name ?? 'Unknown'],
            'created_at' => $c->created_at?->toISOString(),
            'updated_at' => $c->updated_at?->toISOString(),
            'edited' => $c->updated_at && $c->created_at && $c->updated_at->gt($c->created_at),
            'replies_count' => (int) ($c->replies_count ?? 0),
            'last_reply_at' => $c->last_reply_at ?? null,
            'reply_avatars' => $repliers?->get($c->id, []) ?? [],
            'reactions' => $reactions->get($c->id, []),
        ];
    }
}
