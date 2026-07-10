<?php
namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardDocument;
use App\Infrastructure\Repository\CardModelRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardDocumentController extends Controller
{
    /** Extensions accepted as card documents. Deliberately excludes executables/scripts. */
    private const ALLOWED = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,md,rtf,odt,ods,odp,zip';

    public function store(Request $request, int $boardId, int $cardId)
    {
        $this->authorizeWrite($boardId);
        $card = $this->boardCard($boardId, $cardId);

        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:' . self::ALLOWED], // 20MB
        ]);

        $file = $request->file('file');
        $path = $file->store("card-documents/{$card->id}", 'local');
        $position = CardDocument::where('card_id', $card->id)->max('position') + 1;

        $document = CardDocument::create([
            'card_id'       => $card->id,
            'user_id'       => Auth::id(),
            'disk'          => 'local',
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'position'      => $position,
        ]);

        // Push the refreshed card to every board subscriber so the attachment
        // survives a modal close/reopen (the board's in-memory card is updated).
        $this->broadcastCard($boardId, $card->id);

        return response()->json($document->load('uploader:id,name'), 201);
    }

    /**
     * Stream a document back to the client. Read access to the board is enough —
     * the file never has a guessable public URL, so this route is the only way in.
     */
    public function download(int $boardId, int $cardId, int $documentId): StreamedResponse
    {
        $this->authorizeBoard($boardId);
        $this->boardCard($boardId, $cardId);

        $document = CardDocument::where('card_id', $cardId)->findOrFail($documentId);

        return Storage::disk($document->disk ?: 'local')
            ->download($document->path, $document->original_name ?: "document-{$document->id}");
    }

    public function destroy(int $boardId, int $cardId, int $documentId)
    {
        $this->authorizeWrite($boardId);
        $this->boardCard($boardId, $cardId);

        $document = CardDocument::where('card_id', $cardId)->findOrFail($documentId);
        Storage::disk($document->disk ?: 'local')->delete($document->path);
        $document->delete();

        $this->broadcastCard($boardId, $cardId);

        return response()->json(null, 204);
    }

    /** Reload the card with its client-facing relations and broadcast it to the board. */
    private function broadcastCard(int $boardId, int $cardId): void
    {
        $card = Card::with(['assignedUser:id,name', 'createdBy:id,name', 'tags', 'images', 'links', 'documents'])->findOrFail($cardId);
        $board = Board::find($boardId);
        $card->ticket_key = CardModelRepository::composeTicketKey($board?->ticket_prefix, $card->ticket_number);

        broadcast(new BoardEvent($boardId, 'card.updated', $card->toArray()));
    }
}
