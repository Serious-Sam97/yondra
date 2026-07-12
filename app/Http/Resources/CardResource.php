<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Single serialization point for card REST responses.
 *
 * Starts from the model's own array form (so new columns and eager-loaded
 * relations flow through automatically) and, when requested, appends the
 * computed `ticket_key`. Endpoints that historically returned bare cards
 * (archived list, subtasks) use the plain resource so their shape is
 * unchanged; card create/update and the nested cards of a board use the
 * `withTicketKey*` constructors.
 */
class CardResource extends JsonResource
{
    private bool $includeTicketKey = false;

    private ?string $ticketPrefix = null;

    private bool $prefixResolved = false;

    /** Include `ticket_key`, resolving the board's ticket prefix from the card. */
    public static function withTicketKey(Card $card): self
    {
        $resource = new self($card);
        $resource->includeTicketKey = true;

        return $resource;
    }

    /** Include `ticket_key` using an already-known board prefix (avoids per-card board lookups). */
    public static function withTicketKeyFromPrefix(Card $card, ?string $prefix): self
    {
        $resource = self::withTicketKey($card);
        $resource->ticketPrefix = $prefix;
        $resource->prefixResolved = true;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Build the base array first so a lazily-resolved board prefix can never
        // leak a `board` relation key into the payload.
        $data = $this->resource->toArray();

        if ($this->includeTicketKey) {
            $data['ticket_key'] = Card::ticketKey($this->prefix(), $this->resource->ticket_number);
        }

        return $data;
    }

    private function prefix(): ?string
    {
        if ($this->prefixResolved) {
            return $this->ticketPrefix;
        }

        $card = $this->resource;

        return $card->relationLoaded('board')
            ? $card->board?->ticket_prefix
            : Board::whereKey($card->board_id)->value('ticket_prefix');
    }
}
