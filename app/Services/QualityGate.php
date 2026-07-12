<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\TestCase;
use App\Infrastructure\Models\TestRun;

/**
 * QA quality gate: a card may not enter the board's done column while it has
 * test cases whose latest run failed or that were never run. Shared by every
 * path that can move a card between sections (drag reorder + card update),
 * so the gate can't be bypassed by editing the card directly.
 */
class QualityGate
{
    /**
     * Cards among $cardIds that the gate blocks from entering $targetSection.
     * Empty when the gate doesn't apply (QA off, target isn't the done column,
     * cards already in the target section) or nothing blocks.
     *
     * @param  array<int>  $cardIds
     * @return array<int, array{card_id: int, cases: array}>
     */
    public function blocking(Board $board, array $cardIds, Section $targetSection): array
    {
        if (! $board->qa_enabled || ! $board->marksDone($targetSection)) {
            return [];
        }

        $enteringIds = Card::where('board_id', $board->id)
            ->whereIn('id', $cardIds)
            ->where('section_id', '!=', $targetSection->id)
            ->pluck('id')->all();

        $blocking = [];
        foreach ($enteringIds as $cardId) {
            $cases = $this->blockingCases((int) $cardId);
            if ($cases) {
                $blocking[] = ['card_id' => (int) $cardId, 'cases' => $cases];
            }
        }

        return $blocking;
    }

    /** Test cases on a card whose latest run failed, or that were never run. */
    public function blockingCases(int $cardId): array
    {
        $blocking = [];
        $cases = TestCase::where('card_id', $cardId)->get(['id', 'title']);
        foreach ($cases as $case) {
            $latest = TestRun::where('test_case_id', $case->id)
                ->orderByDesc('executed_at')->orderByDesc('id')->first();
            if (! $latest || $latest->status === 'failed') {
                $blocking[] = ['id' => $case->id, 'title' => $case->title, 'status' => $latest?->status ?? 'not_run'];
            }
        }

        return $blocking;
    }
}
