<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Jobs\GenerateCardSummaryJob;
use App\Services\AiAssistService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function aiCard(User $owner): array
{
    $board = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create([
        'board_id' => $board->id,
        'section_id' => $section->id,
        'name' => 'Ship login',
        'description' => 'OAuth flow',
    ]);

    return [$board, $card];
}

// Minimal Anthropic-style SSE body for the end-to-end path.
function aiSse(array $chunks): string
{
    $lines = [];
    foreach ($chunks as $text) {
        $lines[] = 'data: '.json_encode([
            'type' => 'content_block_delta',
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]);
        $lines[] = '';
    }

    return implode("\n", $lines)."\n";
}

it('returns 503 when no AI provider is configured', function () {
    config(['services.ai.driver' => 'anthropic', 'services.ai.anthropic.api_key' => null]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/summarize")
        ->assertStatus(503);
});

it('dispatches the summary job and returns 202 with the request id', function () {
    config(['services.ai.driver' => 'anthropic', 'services.ai.anthropic.api_key' => 'sk-test']);
    Bus::fake();
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/summarize", ['request_id' => 'req-123'])
        ->assertStatus(202)
        ->assertJson(['request_id' => 'req-123']);

    Bus::assertDispatched(GenerateCardSummaryJob::class, function ($job) use ($board, $card) {
        return $job->boardId === $board->id
            && $job->cardId === $card->id
            && $job->requestId === 'req-123';
    });
});

it('denies a user without access to the board', function () {
    config(['services.ai.anthropic.api_key' => 'sk-test']);
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    [$board, $card] = aiCard($owner);

    $this->actingAs($outsider)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/summarize")
        ->assertStatus(403);
});

it('feeds the card content (title + comments) to the provider, wrapped in a <card> block', function () {
    config([
        'services.ai.driver' => 'anthropic',
        'services.ai.anthropic.api_key' => 'sk-test',
        'services.ai.anthropic.base_url' => 'https://api.anthropic.com',
        'services.ai.anthropic.version' => '2023-06-01',
        'services.ai.anthropic.model' => 'claude-opus-4-8',
    ]);
    Http::fake(['api.anthropic.com/*' => Http::response(aiSse(['All ', 'good.']), 200)]);

    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);
    CardComment::create([
        'card_id' => $card->id,
        'user_id' => $owner->id,
        'body' => 'Blocked on the callback URL',
    ]);

    // Broadcasts go to the null connection in tests — assert the outbound provider call.
    app(AiAssistService::class)->summarizeCard($board->id, $card->id, 'req-xyz');

    Http::assertSent(function ($request) {
        $prompt = $request['messages'][0]['content'] ?? '';

        return str_contains($prompt, 'Ship login')                // card title
            && str_contains($prompt, 'Blocked on the callback URL') // comment body
            && str_contains($prompt, '<card>');                     // injection-boundary wrapper
    });
});
