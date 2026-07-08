<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\CardLink;
use Illuminate\Support\Facades\Http;

class GitHubService
{
    /**
     * Parse a GitHub PR/issue URL into its parts.
     * Returns null when the URL isn't a recognizable PR/issue link.
     *
     * @return array{type:string, owner:string, repo:string, number:int}|null
     */
    public function parse(string $url): ?array
    {
        // https://github.com/{owner}/{repo}/(pull|issues)/{number}
        if (!preg_match('#github\.com/([^/]+)/([^/]+)/(pull|issues)/(\d+)#i', $url, $m)) {
            return null;
        }

        return [
            'type'   => $m[3] === 'pull' ? 'pr' : 'issue',
            'owner'  => $m[1],
            'repo'   => $m[2],
            'number' => (int) $m[4],
        ];
    }

    /**
     * Refresh a link's live state from GitHub. Best-effort: without a usable token
     * (board or instance fallback) it leaves the parsed fields as-is and returns
     * the link unchanged, so link-only display still works.
     */
    public function sync(CardLink $link): CardLink
    {
        $board = $link->board ?: Board::find($link->board_id);
        $token = $board?->github_token ?: config('services.github.token');

        if (!$link->owner || !$link->repo || !$link->number || !$token) {
            return $link;
        }

        $base     = rtrim((string) config('services.github.api_url'), '/');
        $endpoint = $link->type === 'pr' ? 'pulls' : 'issues';
        $client   = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Yondra'])
            ->timeout(8);

        $res = $client->get("{$base}/repos/{$link->owner}/{$link->repo}/{$endpoint}/{$link->number}");
        if (!$res->successful()) {
            return $link;
        }

        $this->applyResource($link, $res->json());

        // Best-effort CI checks for PRs, keyed off the head commit's combined status.
        if ($link->type === 'pr' && ($sha = data_get($res->json(), 'head.sha'))) {
            $status = $client->get("{$base}/repos/{$link->owner}/{$link->repo}/commits/{$sha}/status");
            if ($status->successful()) {
                $link->checks_state = $this->mapCombinedStatus($status->json('state'));
            }
        }

        $link->last_synced_at = now();
        $link->save();

        return $link;
    }

    /**
     * Map a GitHub PR/issue resource (from the REST API or a webhook payload's
     * `pull_request`/`issue` object) onto the link's stored fields.
     */
    public function applyResource(CardLink $link, array $data): void
    {
        $merged = (bool) data_get($data, 'merged', false) || filled(data_get($data, 'merged_at'));
        $draft  = (bool) data_get($data, 'draft', false);
        $state  = data_get($data, 'state'); // open | closed

        $link->title  = data_get($data, 'title') ?? $link->title;
        $link->author = data_get($data, 'user.login') ?? $link->author;
        $link->html_url = data_get($data, 'html_url') ?? $link->html_url;
        $link->merged = $merged;
        $link->state  = $merged ? 'merged' : ($draft && $state === 'open' ? 'draft' : $state);
    }

    private function mapCombinedStatus(?string $state): ?string
    {
        return match ($state) {
            'success' => 'success',
            'failure', 'error' => 'failure',
            'pending' => 'pending',
            default   => null,
        };
    }
}
