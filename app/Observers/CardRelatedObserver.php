<?php

namespace App\Observers;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardChecklistItem;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\CardDocument;
use App\Infrastructure\Models\CardImage;
use App\Infrastructure\Models\CardInvoice;
use App\Infrastructure\Models\CardLink;
use App\Infrastructure\Models\CardPayment;
use App\Infrastructure\Models\TestCase;
use App\Infrastructure\Models\TestRun;
use App\Services\CardHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * History for things that hang off a card (checklist, comments, files, links,
 * payments, invoice, QA). One observer for all of them: each event maps to a
 * one-line entry on the parent card, or nothing (e.g. reordering a checklist).
 */
class CardRelatedObserver
{
    public function created(Model $model): void
    {
        $entry = match (true) {
            $model instanceof CardChecklistItem => ['checklist.added', ['text' => $model->text]],
            $model instanceof CardComment => ['comment.added', ['excerpt' => $this->excerpt($model->body), 'reply' => $model->parent_id !== null]],
            $model instanceof CardImage => ['attachment.added', ['name' => $model->original_name]],
            $model instanceof CardDocument => ['document.added', ['name' => $model->original_name]],
            $model instanceof CardLink => ['link.added', ['title' => $this->linkTitle($model), 'url' => $model->html_url ?: $model->url]],
            $model instanceof CardPayment => ['payment.added', ['amount' => (float) $model->amount, 'note' => $model->note]],
            $model instanceof CardInvoice => ['invoice.issued', ['number' => $model->number, 'amount' => (float) $model->amount, 'currency' => $model->currency]],
            $model instanceof TestCase => ['qa.case_added', ['title' => $model->title]],
            $model instanceof TestRun => ['qa.run', ['title' => TestCase::whereKey($model->test_case_id)->value('title'), 'status' => $model->status, 'environment' => $model->environment]],
            default => null,
        };

        $this->write($model, $entry);
    }

    public function updated(Model $model): void
    {
        $entry = match (true) {
            $model instanceof CardChecklistItem => $this->checklistUpdate($model),
            $model instanceof CardComment && $model->wasChanged('body') => ['comment.edited', ['excerpt' => $this->excerpt($model->body)]],
            $model instanceof CardLink && $model->wasChanged(['state', 'merged']) => ['link.status', [
                'title' => $this->linkTitle($model),
                'from' => $this->linkState($model->getRawOriginal('state'), (bool) $model->getRawOriginal('merged')),
                'to' => $this->linkState($model->state, (bool) $model->merged),
            ]],
            $model instanceof TestCase && $model->wasChanged('bug_card_id') && $model->bug_card_id => ['qa.bug_linked', [
                'title' => $model->title,
                'bug_card_id' => $model->bug_card_id,
                'bug_name' => Card::whereKey($model->bug_card_id)->value('name'),
            ]],
            default => null,
        };

        $this->write($model, $entry);
    }

    public function deleted(Model $model): void
    {
        $entry = match (true) {
            $model instanceof CardChecklistItem => ['checklist.removed', ['text' => $model->text]],
            $model instanceof CardComment => ['comment.deleted', ['excerpt' => $this->excerpt($model->body)]],
            $model instanceof CardImage => ['attachment.removed', ['name' => $model->original_name]],
            $model instanceof CardDocument => ['document.removed', ['name' => $model->original_name]],
            $model instanceof CardLink => ['link.removed', ['title' => $this->linkTitle($model)]],
            $model instanceof CardPayment => ['payment.removed', ['amount' => (float) $model->amount, 'note' => $model->note]],
            $model instanceof TestCase => ['qa.case_removed', ['title' => $model->title]],
            default => null,
        };

        $this->write($model, $entry);
    }

    private function checklistUpdate(CardChecklistItem $item): ?array
    {
        if ($item->wasChanged('is_done')) {
            return [$item->is_done ? 'checklist.completed' : 'checklist.reopened', ['text' => $item->text]];
        }
        if ($item->wasChanged('text')) {
            return ['checklist.edited', ['from' => $item->getRawOriginal('text'), 'to' => $item->text]];
        }

        return null; // position-only reorder
    }

    /** @param  array{0: string, 1: array}|null  $entry */
    private function write(Model $model, ?array $entry): void
    {
        if ($entry === null) {
            return;
        }

        $cardId = $model instanceof TestRun
            ? TestCase::whereKey($model->test_case_id)->value('card_id')
            : $model->card_id;

        if ($cardId && ($card = Card::find($cardId))) {
            CardHistory::record($card, $entry[0], [], $entry[1]);
        }
    }

    private function excerpt(?string $html): string
    {
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $html))));

        return mb_strlen($text) > 140 ? mb_substr($text, 0, 140).'…' : $text;
    }

    private function linkTitle(CardLink $link): string
    {
        if ($link->title) {
            return $link->title;
        }

        return $link->repo && $link->number ? "{$link->repo}#{$link->number}" : (string) $link->url;
    }

    private function linkState(?string $state, bool $merged): ?string
    {
        return $merged ? 'merged' : $state;
    }
}
