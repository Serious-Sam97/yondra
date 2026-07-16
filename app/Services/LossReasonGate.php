<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;

/**
 * Loss-reason gate (YON-66): a CRM deal may not enter the board's Lost stage
 * without a reason chosen from the board's configured loss_reasons list. Shared
 * by every path that can move a card between sections (drag reorder + card
 * update), so the requirement can't be bypassed by editing the card directly.
 * Mirrors QualityGate's shape.
 */
class LossReasonGate
{
    /**
     * Card ids among $cardIds that the gate blocks from entering $targetSection.
     * Empty when the gate doesn't apply (target isn't the Lost stage, cards are
     * already there) or a valid reason was supplied.
     *
     * @param  array<int>  $cardIds
     * @return array<int>
     */
    public function blocking(Board $board, array $cardIds, Section $targetSection, ?string $reason): array
    {
        if (! $board->marksLost($targetSection) || $this->isValidReason($board, $reason)) {
            return [];
        }

        // Only cards actually entering the Lost stage need a reason.
        return Card::where('board_id', $board->id)
            ->whereIn('id', $cardIds)
            ->where('section_id', '!=', $targetSection->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** The reasons offered for this board (falls back to the shared defaults). */
    public function reasonsFor(Board $board): array
    {
        $reasons = $board->loss_reasons;

        return is_array($reasons) && $reasons !== [] ? array_values($reasons) : Board::DEFAULT_LOSS_REASONS;
    }

    private function isValidReason(Board $board, ?string $reason): bool
    {
        $reason = $reason !== null ? trim($reason) : '';

        return $reason !== '' && in_array($reason, $this->reasonsFor($board), true);
    }
}
