<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Http\Resources\CardResource;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\Section;
use App\Jobs\SendIntakeConfirmationJob;
use App\Services\CardService;
use App\Services\Intake\IntakeFileImporter;
use App\Services\Intake\IntakeSubmissionParser;
use App\Services\TagService;
use Illuminate\Http\Request;

/**
 * Public intake ingress: an external form (JotForm) POSTs a submission here and
 * we open a card in the board's first column. No header auth — the unguessable
 * per-board token in the URL IS the credential (same pattern as the GitHub
 * webhook and the QA CI hook). See IntakeSubmissionParser for the field mapping.
 */
class IntakeWebhookController extends Controller
{
    public function __construct(
        private CardService $cards,
        private IntakeSubmissionParser $parser,
        private IntakeFileImporter $files,
        private TagService $tags,
    ) {}

    public function handle(Request $request, string $token)
    {
        $board = Board::where('intake_token', $token)->first();
        if (! $board) {
            return response()->json(['message' => 'Unknown or disabled intake endpoint.'], 404);
        }

        // First column by order; a board with no sections can't hold cards.
        $section = Section::where('board_id', $board->id)->orderBy('order')->first();
        if (! $section) {
            return response()->json(['message' => 'Board has no columns to receive intake.'], 422);
        }

        $parsed = $this->parser->parse($request->all(), $board->intake_field_map ?? []);

        $card = $this->cards->create([
            'board_id' => $board->id,
            'section_id' => $section->id,
            'name' => $parsed['name'],
            'description' => $parsed['description'],
            'priority' => $parsed['priority'] ?? 'medium',
            'value' => $parsed['value'],
            'story_points' => $parsed['story_points'],
            'due_date' => $parsed['due_date'],
        ]);

        $this->syncTags($board, $card, $parsed['tags']);
        $this->attachContact($board, $card->id, $parsed['contact']);

        if ($parsed['files'] !== []) {
            $this->files->import($card, $parsed['files']);
        }

        // Reload with the relations the board UI is typed against, then broadcast so
        // the new card appears live for anyone watching the board.
        $card = $card->fresh()->load(['assignedUser:id,name', 'contact', 'createdBy:id,name', 'tags', 'images', 'links', 'documents']);
        $payload = CardResource::withTicketKeyFromPrefix($card, $board->ticket_prefix)->resolve();

        BoardActivity::create([
            'board_id' => $board->id,
            'user_id' => null, // system-authored intake
            'type' => 'card_created',
            'description' => 'received intake "'.$card->name.'"',
        ]);

        broadcast(new BoardEvent($board->id, 'card.created', $payload));

        return response()->json(['ok' => true, 'card_id' => $card->id], 201);
    }

    /**
     * Turn mapped tag names into real board tags (created on demand) and attach them.
     *
     * @param  array<int,string>  $names
     */
    private function syncTags(Board $board, Card $card, array $names): void
    {
        if ($names === []) {
            return;
        }

        $ids = [];
        foreach ($names as $name) {
            $ids[] = $this->tags->findOrCreateByName($board->id, $name)->id;
        }
        $card->tags()->sync(array_unique($ids));
    }

    /**
     * Mint a board-scoped contact from the parsed {name,email,phone} and link it to
     * the card. Skipped entirely when the form carried no contact details.
     *
     * @param  array{name:?string,email:?string,phone:?string}  $contact
     */
    private function attachContact(Board $board, int $cardId, array $contact): void
    {
        if (! $contact['name'] && ! $contact['email'] && ! $contact['phone']) {
            return;
        }

        $model = Contact::create([
            'board_id' => $board->id,
            'name' => $contact['name'],
            'email' => $contact['email'],
            'phone' => $contact['phone'],
        ]);

        $board->cards()->whereKey($cardId)->update(['contact_id' => $model->id]);

        // Double opt-in (YON-52): when the board runs the opt-in flow, email the
        // submitter a confirm link so they whitelist us before any quote is sent.
        if ($board->require_optin_before_email && $model->email) {
            SendIntakeConfirmationJob::dispatch((int) $model->id);
        }
    }
}
