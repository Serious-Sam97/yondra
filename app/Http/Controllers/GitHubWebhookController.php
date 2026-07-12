<?php

namespace App\Http\Controllers;

use App\Events\BoardEvent;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardLink;
use App\Services\GitHubService;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    public function __construct(private GitHubService $github) {}

    public function handle(Request $request, int $boardId)
    {
        $board = Board::find($boardId);
        if (! $board || ! $board->github_webhook_secret) {
            return response()->json(['message' => 'Webhook not configured for this board.'], 404);
        }

        if (! $this->signatureValid($request, $board->github_webhook_secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = $request->header('X-GitHub-Event');
        $payload = $request->json()->all();

        if ($event === 'ping') {
            return response()->json(['ok' => true]);
        }

        $affectedCardIds = match ($event) {
            'pull_request' => $this->applyResourceEvent($board, 'pr', data_get($payload, 'pull_request'), $payload),
            'issues' => $this->applyResourceEvent($board, 'issue', data_get($payload, 'issue'), $payload),
            'check_suite' => $this->applyCheckSuite($board, $payload),
            default => [],
        };

        foreach (array_unique($affectedCardIds) as $cardId) {
            $this->broadcastCard($board, $cardId);
        }

        return response()->json(['ok' => true, 'updated' => count(array_unique($affectedCardIds))]);
    }

    /** @return int[] card ids whose links were updated */
    private function applyResourceEvent(Board $board, string $type, ?array $resource, array $payload): array
    {
        if (! $resource) {
            return [];
        }

        [$owner, $repo] = $this->repoParts($payload);
        $number = data_get($resource, 'number');
        if (! $number) {
            return [];
        }

        $links = CardLink::where('board_id', $board->id)
            ->where('type', $type)
            ->where('owner', $owner)
            ->where('repo', $repo)
            ->where('number', $number)
            ->get();

        foreach ($links as $link) {
            $this->github->applyResource($link, $resource);
            $link->last_synced_at = now();
            $link->save();
        }

        return $links->pluck('card_id')->all();
    }

    /** @return int[] card ids whose links' checks were updated */
    private function applyCheckSuite(Board $board, array $payload): array
    {
        $conclusion = data_get($payload, 'check_suite.conclusion'); // success|failure|null(=pending)
        $prs = data_get($payload, 'check_suite.pull_requests', []);
        [$owner, $repo] = $this->repoParts($payload);

        $checks = match ($conclusion) {
            'success' => 'success',
            'failure', 'timed_out', 'cancelled', 'action_required' => 'failure',
            default => 'pending',
        };

        $cardIds = [];
        foreach ($prs as $pr) {
            $links = CardLink::where('board_id', $board->id)
                ->where('type', 'pr')->where('owner', $owner)->where('repo', $repo)
                ->where('number', data_get($pr, 'number'))
                ->get();
            foreach ($links as $link) {
                $link->checks_state = $checks;
                $link->save();
                $cardIds[] = $link->card_id;
            }
        }

        return $cardIds;
    }

    private function repoParts(array $payload): array
    {
        $full = (string) data_get($payload, 'repository.full_name');
        if (str_contains($full, '/')) {
            return explode('/', $full, 2);
        }

        return [data_get($payload, 'repository.owner.login'), data_get($payload, 'repository.name')];
    }

    private function signatureValid(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-Hub-Signature-256');
        if ($header === '') {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    private function broadcastCard(Board $board, int $cardId): void
    {
        $card = Card::with(['assignedUser:id,name', 'createdBy:id,name', 'tags', 'images', 'links', 'documents'])->find($cardId);
        if (! $card) {
            return;
        }
        $card->ticket_key = Card::ticketKey($board->ticket_prefix, $card->ticket_number);
        broadcast(new BoardEvent($board->id, 'card.updated', $card->toArray()));
    }
}
