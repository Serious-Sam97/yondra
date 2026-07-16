<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Http\Resources\CardResource;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\ImportModel;
use App\Infrastructure\Models\Section;
use App\Services\CardImport\CardImportParser;
use App\Services\CardImport\ImportModelMapper;
use App\Services\CardService;
use App\Services\TagService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Custom JSON card importer (YON-121). Turns a user-supplied JSON model — pasted
 * or uploaded in the board's Import tool — into real cards. Each row is created
 * independently so one malformed entry never sinks the whole batch; the response
 * reports what was created and, per index, what was rejected and why.
 *
 * Field spellings are normalised by CardImportParser; section resolution needs
 * the board's columns so it lives here.
 */
class CardImportController extends Controller
{
    public function __construct(
        private CardImportParser $parser,
        private ImportModelMapper $modelMapper,
        private CardService $cards,
        private TagService $tags,
    ) {}

    public function store(Request $request, int $boardId)
    {
        $board = $this->authorizeWrite($boardId);

        // A custom project model is used via an explicit { model_id, payload }
        // envelope; without it the body IS the JSON (the built-in flat/canvas
        // path). Requiring both keys keeps a stray `model_id` inside a flat card
        // from being mistaken for model mode.
        if ($request->has('model_id') && $request->has('payload')) {
            $model = $this->resolveModel($board, (int) $request->input('model_id'));
            if ($model === null) {
                return response()->json(['message' => 'Import model not found for this board.'], 422);
            }
            [$rows, $shapeError] = $this->modelMapper->apply($model->toArray(), $request->input('payload'));
        } else {
            [$rows, $shapeError] = $this->parser->extractRows($request->all());
        }

        if ($shapeError !== null) {
            return response()->json(['message' => $shapeError], 422);
        }

        // Columns the caller may target, indexed by lower-cased name for matching.
        $sections = Section::where('board_id', $boardId)->orderBy('order')->get();
        if ($sections->isEmpty()) {
            return response()->json(['message' => 'Board has no columns to import cards into.'], 422);
        }
        $defaultSection = $sections->first();
        $byName = $sections->keyBy(fn (Section $s) => mb_strtolower(trim($s->name)));

        $created = [];
        $errors = [];

        foreach ($rows as $index => $raw) {
            [$fields, $rowError] = $this->parser->normaliseRow($raw);
            if ($rowError !== null) {
                $errors[] = ['index' => $index, 'message' => $rowError];

                continue;
            }

            $section = $this->resolveSection($fields, $sections, $byName, $defaultSection);
            if ($section === null) {
                $hint = $fields['section_id'] ?? $fields['section'];
                $errors[] = ['index' => $index, 'message' => "Unknown column \"{$hint}\"."];

                continue;
            }

            $card = $this->cards->create([
                'board_id' => $boardId,
                'section_id' => $section->id,
                'name' => $fields['name'],
                'description' => $fields['description'],
                'priority' => $fields['priority'],
                'due_date' => $fields['due_date'],
                'value' => $fields['value'],
                'story_points' => $fields['story_points'],
            ]);

            if (! empty($fields['tags'])) {
                $ids = [];
                foreach ($fields['tags'] as $tagName) {
                    $ids[] = $this->tags->findOrCreateByName($boardId, $tagName)->id;
                }
                $card->tags()->sync(array_unique($ids));
            }

            $this->syncContact($boardId, $card, $fields['contact'] ?? null);

            $card = $card->fresh()->load(['assignedUser:id,name', 'contact', 'createdBy:id,name', 'tags', 'images', 'links', 'documents']);
            $payload = CardResource::withTicketKey($card)->resolve();
            broadcast(new BoardEvent($boardId, 'card.created', $payload));
            $created[] = $payload;
        }

        if (! empty($created)) {
            BoardActivity::create([
                'board_id' => $boardId,
                'user_id' => Auth::id(),
                'type' => 'cards_imported',
                'description' => 'imported '.count($created).' card'.(count($created) === 1 ? '' : 's').' from JSON',
            ]);
        }

        return response()->json([
            'created' => $created,
            'created_count' => count($created),
            'errors' => $errors,
            'error_count' => count($errors),
        ], 201);
    }

    /**
     * Load a custom import model, scoped to the board's project so a board can
     * only apply models from its own project. Returns null when the board has no
     * project or the id doesn't resolve — surfaced as a whole-payload 422.
     */
    private function resolveModel(Board $board, int $modelId): ?ImportModel
    {
        if ($board->project_id === null) {
            return null;
        }

        return ImportModel::where('project_id', $board->project_id)->find($modelId);
    }

    /**
     * Pick the target column: an explicit section_id (must belong to the board),
     * else a case-insensitive name match, else the board's first column. Returns
     * null only when the caller named a column that doesn't exist — that surfaces
     * as a per-row error rather than silently landing in the wrong place.
     *
     * @param  Collection<int, Section>  $sections
     * @param  Collection<string, Section>  $byName
     */
    private function resolveSection(array $fields, $sections, $byName, Section $default): ?Section
    {
        if ($fields['section_id'] !== null) {
            return $sections->firstWhere('id', $fields['section_id']);
        }

        if ($fields['section'] !== null && trim($fields['section']) !== '') {
            return $byName->get(mb_strtolower(trim($fields['section'])));
        }

        return $default;
    }

    /**
     * Create a board-scoped contact from an imported card's `{name,email,phone}`
     * and link it. Mirrors CardController::syncCardContact; a fully blank contact
     * is a no-op. Each imported card mints its own contact row.
     */
    private function syncContact(int $boardId, Card $card, ?array $contact): void
    {
        if ($contact === null) {
            return;
        }

        $name = trim((string) ($contact['name'] ?? ''));
        $email = trim((string) ($contact['email'] ?? ''));
        $phone = trim((string) ($contact['phone'] ?? ''));
        if ($name === '' && $email === '' && $phone === '') {
            return;
        }

        $model = Contact::create([
            'board_id' => $boardId,
            'name' => $name ?: null,
            'email' => $email ?: null,
            'phone' => $phone ?: null,
        ]);
        $card->update(['contact_id' => $model->id]);
    }
}
