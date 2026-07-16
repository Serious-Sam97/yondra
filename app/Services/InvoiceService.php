<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardDocument;
use App\Infrastructure\Models\CardInvoice;
use App\Infrastructure\Models\CardPayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Issues a nota fiscal / invoice document for a CRM deal (YON-68). Fired by the
 * 100%-payment milestone action or a manual "issue" click. Renders a PDF from the
 * board's issuer details + the card's contact and payment ledger, attaches it as a
 * system-owned CardDocument, and records it in the card_invoices ledger.
 *
 * Idempotent per card: re-issuing regenerates the PDF but keeps the same invoice
 * number, so a client never sees the number jump. This is a simplified invoice
 * document, NOT a SEFAZ-registered fiscal NF-e.
 */
class InvoiceService
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Issue (or re-issue) the card's invoice. Returns the ledger row with its
     * freshly-generated document attached. Throws if the card has no board.
     */
    public function issueForCard(Card $card, ?int $userId = null): CardInvoice
    {
        $card->loadMissing(['board', 'contact']);
        $board = $card->board;
        if (! $board) {
            throw new \RuntimeException('Card has no board; cannot issue an invoice.');
        }

        $currency = $board->currency ?: 'BRL';
        // Bill the deal value; fall back to what was actually paid when no value is set.
        $amount = $card->value !== null ? (float) $card->value : (float) $card->amount_paid;

        $issuer = $this->issuerSnapshot($board);
        $recipient = $this->recipientSnapshot($card);

        return DB::transaction(function () use ($card, $board, $currency, $amount, $issuer, $recipient, $userId) {
            $existing = CardInvoice::where('card_id', $card->id)->lockForUpdate()->first();

            // Keep the number stable across re-issues; allocate the next per-board
            // number only for a brand-new invoice.
            $number = $existing?->number
                ?? ((int) CardInvoice::where('board_id', $board->id)->max('number') + 1);

            $document = $this->renderAndStore($card, $board, $currency, $amount, $issuer, $recipient, $number, $userId);

            $invoice = CardInvoice::updateOrCreate(
                ['card_id' => (int) $card->id],
                [
                    'board_id' => (int) $board->id,
                    'document_id' => (int) $document->id,
                    'number' => $number,
                    'currency' => $currency,
                    'amount' => $amount,
                    'issuer' => $issuer,
                    'recipient' => $recipient,
                    'issued_at' => now(),
                    'issued_by_user_id' => $userId,
                ]
            );

            // Drop the superseded PDF (file + row) only after the new one is wired up.
            if ($existing && $existing->document_id && $existing->document_id !== $document->id) {
                $this->deleteDocument((int) $existing->document_id);
            }

            return $invoice->refresh();
        });
    }

    /** Render the PDF and persist it as a private CardDocument, returning the row. */
    private function renderAndStore(
        Card $card,
        $board,
        string $currency,
        float $amount,
        array $issuer,
        array $recipient,
        int $number,
        ?int $userId,
    ): CardDocument {
        $payments = CardPayment::where('card_id', $card->id)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get()
            ->map(fn (CardPayment $p) => [
                'amount' => $this->payments->formatMoney((float) $p->amount, $currency),
                'note' => $p->note,
                'paid_at' => $p->paid_at?->format('d/m/Y'),
            ])
            ->all();

        $paid = (float) $card->amount_paid;

        $pdf = Pdf::loadView('invoices.nota-fiscal', [
            'number' => str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'issued_at' => now()->format('d/m/Y'),
            'issuer' => $issuer,
            'recipient' => $recipient,
            'description' => $card->name,
            'currency' => $currency,
            'amount' => $this->payments->formatMoney($amount, $currency),
            'paid' => $this->payments->formatMoney($paid, $currency),
            'remaining' => $this->payments->formatMoney(max(0.0, $amount - $paid), $currency),
            'payments' => $payments,
        ])->setPaper('a4');

        $body = $pdf->output();
        $filename = "nota-fiscal-{$number}.pdf";
        $path = "card-documents/{$card->id}/".bin2hex(random_bytes(20)).'.pdf';
        Storage::disk('local')->put($path, $body);

        $position = (int) CardDocument::where('card_id', $card->id)->max('position') + 1;

        return CardDocument::create([
            'card_id' => (int) $card->id,
            'user_id' => $userId, // null when generated by the milestone engine
            'disk' => 'local',
            'path' => $path,
            'original_name' => $filename,
            'mime_type' => 'application/pdf',
            'size' => strlen($body),
            'position' => $position,
        ]);
    }

    /** Remove a superseded document's file and row. */
    private function deleteDocument(int $documentId): void
    {
        $doc = CardDocument::find($documentId);
        if (! $doc) {
            return;
        }
        Storage::disk($doc->disk ?: 'local')->delete($doc->path);
        $doc->delete();
    }

    /** The issuer block: board's configured details, falling back to the board name. */
    private function issuerSnapshot($board): array
    {
        $issuer = is_array($board->invoice_issuer) ? $board->invoice_issuer : [];

        return [
            'name' => trim((string) ($issuer['name'] ?? '')) ?: $board->name,
            'tax_id' => trim((string) ($issuer['tax_id'] ?? '')) ?: null,
            'address' => trim((string) ($issuer['address'] ?? '')) ?: null,
            'email' => trim((string) ($issuer['email'] ?? '')) ?: null,
            'phone' => trim((string) ($issuer['phone'] ?? '')) ?: null,
            'footer' => trim((string) ($issuer['footer'] ?? '')) ?: null,
        ];
    }

    /** The recipient block, snapshotted from the card's contact (or its name). */
    private function recipientSnapshot(Card $card): array
    {
        $contact = $card->contact;

        return [
            'name' => $contact?->name ?: $card->name,
            'email' => $contact?->email ?: null,
            'phone' => $contact?->phone ?: null,
        ];
    }
}
