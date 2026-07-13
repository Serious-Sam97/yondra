<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\WhatsappConversation;
use App\Infrastructure\Models\WhatsappMessage;
use App\Jobs\GenerateAiAssistJob;
use App\Jobs\GenerateBoardSummaryJob;
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

function configureAnthropic(): void
{
    config([
        'services.ai.driver' => 'anthropic',
        'services.ai.anthropic.api_key' => 'sk-test',
        'services.ai.anthropic.base_url' => 'https://api.anthropic.com',
        'services.ai.anthropic.version' => '2023-06-01',
        'services.ai.anthropic.model' => 'claude-opus-4-8',
    ]);
}

it('returns 503 when no AI provider is configured', function () {
    config(['services.ai.driver' => 'anthropic', 'services.ai.anthropic.api_key' => null]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/summarize")
        ->assertStatus(503);
});

it('rejects an unknown action with 404', function () {
    configureAnthropic();
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/bogus")
        ->assertStatus(404);
});

it('dispatches the job with the action and returns 202', function () {
    configureAnthropic();
    Bus::fake();
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/summarize", ['request_id' => 'req-123'])
        ->assertStatus(202)
        ->assertJson(['request_id' => 'req-123']);

    Bus::assertDispatched(GenerateAiAssistJob::class, function ($job) use ($board, $card) {
        return $job->boardId === $board->id
            && $job->cardId === $card->id
            && $job->requestId === 'req-123'
            && $job->action === 'summarize'
            && $job->options === [];
    });
});

it('passes action options (describe prompt, rewrite mode/language) through to the job', function () {
    configureAnthropic();
    Bus::fake();
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);
    $base = "/api/boards/{$board->id}/cards/{$card->id}/ai";

    $this->actingAs($owner)->postJson("{$base}/describe", ['prompt' => 'focus on auth'])->assertStatus(202);
    Bus::assertDispatched(GenerateAiAssistJob::class, fn ($job) => $job->action === 'describe' && $job->options === ['prompt' => 'focus on auth']);

    $this->actingAs($owner)->postJson("{$base}/rewrite", ['mode' => 'translate', 'language' => 'French'])->assertStatus(202);
    Bus::assertDispatched(GenerateAiAssistJob::class, fn ($job) => $job->action === 'rewrite' && $job->options === ['mode' => 'translate', 'language' => 'French']);
});

it('denies a user without access to the board', function () {
    configureAnthropic();
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    [$board, $card] = aiCard($owner);

    $this->actingAs($outsider)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/summarize")
        ->assertStatus(403);
});

it('summarize: feeds card title + comments to the provider, wrapped in a <card> block', function () {
    configureAnthropic();
    Http::fake(['api.anthropic.com/*' => Http::response(aiSse(['All ', 'good.']), 200)]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);
    CardComment::create(['card_id' => $card->id, 'user_id' => $owner->id, 'body' => 'Blocked on the callback URL']);

    app(AiAssistService::class)->run($board->id, $card->id, 'req', 'summarize', []);

    Http::assertSent(function ($request) {
        $user = $request['messages'][0]['content'] ?? '';

        return str_contains($user, 'Ship login')
            && str_contains($user, 'Blocked on the callback URL')
            && str_contains($user, '<card>');
    });
});

it('tests: prompts the model as a QA engineer for Gherkin', function () {
    configureAnthropic();
    Http::fake(['api.anthropic.com/*' => Http::response(aiSse(['Scenario']), 200)]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    app(AiAssistService::class)->run($board->id, $card->id, 'req', 'tests', []);

    Http::assertSent(fn ($request) => str_contains($request['system'], 'QA engineer')
        && str_contains($request['system'], 'Gherkin'));
});

it('reply: builds a Customer/Us transcript from the WhatsApp thread', function () {
    configureAnthropic();
    Http::fake(['api.anthropic.com/*' => Http::response(aiSse(['Sure!']), 200)]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);
    $conversation = WhatsappConversation::create([
        'board_id' => $board->id, 'card_id' => $card->id, 'wa_phone' => '15551230000', 'contact_name' => 'Sam',
    ]);
    WhatsappMessage::create([
        'conversation_id' => $conversation->id, 'direction' => 'in', 'type' => 'text', 'body' => 'Where is my order?',
    ]);

    app(AiAssistService::class)->run($board->id, $card->id, 'req', 'reply', []);

    Http::assertSent(function ($request) {
        $user = $request['messages'][0]['content'] ?? '';

        return str_contains($user, 'Customer: Where is my order?')
            && str_contains($request['system'], 'WhatsApp reply');
    });
});

it('rewrite/translate: uses the given text as the source and asks for the target language', function () {
    configureAnthropic();
    Http::fake(['api.anthropic.com/*' => Http::response(aiSse(['Bonjour']), 200)]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    app(AiAssistService::class)->run($board->id, $card->id, 'req', 'rewrite', [
        'mode' => 'translate', 'language' => 'French', 'text' => 'Hello team',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request['messages'][0]['content'], '<text>')
            && str_contains($request['messages'][0]['content'], 'Hello team')
            && str_contains($request['system'], 'Translate the text into French');
    });
});

it('suggestPoints: returns a snapped Fibonacci estimate + rationale', function () {
    configureAnthropic();
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => '{"points": 4, "rationale": "Moderate scope."}']],
    ], 200)]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/points")
        ->assertOk()
        ->assertJson(['points' => 3, 'rationale' => 'Moderate scope.']); // 4 snaps to nearest (3)
});

it('suggestPoints: 422 when the model answer is not JSON', function () {
    configureAnthropic();
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'sorry, I cannot estimate this']],
    ], 200)]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/points")
        ->assertStatus(422);
});

it('suggestPoints: 503 when unconfigured and 403 for outsiders', function () {
    config(['services.ai.driver' => 'anthropic', 'services.ai.anthropic.api_key' => null]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);
    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/points")
        ->assertStatus(503);

    config(['services.ai.anthropic.api_key' => 'sk-test']);
    $this->actingAs(User::factory()->create())
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/points")
        ->assertStatus(403);
});

it('suggestTriage: validates tags/priority/assignee against the board and drops invented ids', function () {
    configureAnthropic();
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);
    $tag = \App\Infrastructure\Models\Tag::create(['board_id' => $board->id, 'name' => 'bug', 'color' => '#f00']);

    // Model returns one real tag id, one bogus id, a valid priority, and the owner as assignee.
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => json_encode([
            'tag_ids' => [$tag->id, 99999],
            'priority' => 'high',
            'assignee_id' => $owner->id,
            'rationale' => 'Looks like a defect.',
        ])]],
    ], 200)]);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/triage")
        ->assertOk()
        ->assertExactJson([
            'tag_ids' => [$tag->id], // 99999 dropped (not a board tag)
            'priority' => 'high',
            'assignee_id' => $owner->id,
            'rationale' => 'Looks like a defect.',
        ]);
});

it('suggestTriage: nulls out an assignee who is not a board member', function () {
    configureAnthropic();
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    [$board, $card] = aiCard($owner);

    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => json_encode([
            'tag_ids' => [],
            'priority' => 'bogus',
            'assignee_id' => $stranger->id,
            'rationale' => '',
        ])]],
    ], 200)]);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/triage")
        ->assertOk()
        ->assertJson(['priority' => null, 'assignee_id' => null, 'tag_ids' => []]);
});

it('suggestSubtasks: returns a sanitised list of subtask titles + rationale', function () {
    configureAnthropic();
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    // Model returns a mix of good titles, a blank, and one with surrounding whitespace.
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => json_encode([
            'subtasks' => ['Add coupon field to checkout', '  Validate the code  ', '', 'Update the order total'],
            'rationale' => 'Split into input, validation, and total steps.',
        ])]],
    ], 200)]);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/subtasks")
        ->assertOk()
        ->assertExactJson([
            'subtasks' => ['Add coupon field to checkout', 'Validate the code', 'Update the order total'],
            'rationale' => 'Split into input, validation, and total steps.',
        ]);
});

it('suggestSubtasks: returns an empty list when the card is too thin to break down', function () {
    configureAnthropic();
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);

    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => json_encode(['subtasks' => [], 'rationale' => 'Not enough detail.'])]],
    ], 200)]);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/subtasks")
        ->assertOk()
        ->assertJson(['subtasks' => [], 'rationale' => 'Not enough detail.']);
});

it('suggestSubtasks: 503 when unconfigured and 403 for outsiders', function () {
    config(['services.ai.driver' => 'anthropic', 'services.ai.anthropic.api_key' => null]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);
    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/subtasks")
        ->assertStatus(503);

    config(['services.ai.anthropic.api_key' => 'sk-test']);
    $this->actingAs(User::factory()->create())
        ->postJson("/api/boards/{$board->id}/cards/{$card->id}/ai/subtasks")
        ->assertStatus(403);
});

it('standup: dispatches the board summary job and returns 202', function () {
    configureAnthropic();
    Bus::fake();
    $owner = User::factory()->create();
    [$board] = aiCard($owner);

    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/ai/standup", ['request_id' => 'req-sd'])
        ->assertStatus(202)
        ->assertJson(['request_id' => 'req-sd']);

    Bus::assertDispatched(GenerateBoardSummaryJob::class, fn ($job) => $job->boardId === $board->id && $job->requestId === 'req-sd');
});

it('standup: the queued job actually runs and calls the provider (no Bus::fake)', function () {
    configureAnthropic();
    Http::fake(['api.anthropic.com/*' => Http::response(aiSse(['Standup:']), 200)]);
    $owner = User::factory()->create();
    [$board] = aiCard($owner);

    // Queue is sync in tests, so dispatch runs the job inline — this exercises the full
    // controller → job → streamBoardSummary → driver path.
    $this->actingAs($owner)
        ->postJson("/api/boards/{$board->id}/ai/standup")
        ->assertStatus(202);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/messages'));
});

it('standup: 503 when unconfigured, 403 for outsiders', function () {
    config(['services.ai.driver' => 'anthropic', 'services.ai.anthropic.api_key' => null]);
    $owner = User::factory()->create();
    [$board] = aiCard($owner);
    $this->actingAs($owner)->postJson("/api/boards/{$board->id}/ai/standup")->assertStatus(503);

    config(['services.ai.anthropic.api_key' => 'sk-test']);
    $this->actingAs(User::factory()->create())
        ->postJson("/api/boards/{$board->id}/ai/standup")
        ->assertStatus(403);
});

it('standup: feeds the board columns + cards (with flags) to the provider', function () {
    configureAnthropic();
    Http::fake(['api.anthropic.com/*' => Http::response(aiSse(['Standup:']), 200)]);
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner); // section "To Do", card "Ship login"

    app(AiAssistService::class)->streamBoardSummary($board->id, 'req', null);

    Http::assertSent(function ($request) {
        $user = $request['messages'][0]['content'] ?? '';

        return str_contains($user, '<board>')
            && str_contains($user, 'To Do')       // column
            && str_contains($user, 'Ship login'); // card
    });
});

it('rate-limits the AI endpoints per user (throttle:ai)', function () {
    configureAnthropic();
    Bus::fake();
    $owner = User::factory()->create();
    [$board, $card] = aiCard($owner);
    $url = "/api/boards/{$board->id}/cards/{$card->id}/ai/summarize";

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($owner)->postJson($url)->assertStatus(202);
    }
    $this->actingAs($owner)->postJson($url)->assertStatus(429);
});

it('rewrite: errors gracefully when there is nothing to rewrite', function () {
    configureAnthropic();
    Http::fake(); // no HTTP call should be made
    $owner = User::factory()->create();
    $board = Board::create(['user_id' => $owner->id, 'name' => 'B', 'description' => '', 'type' => 'kanban']);
    $section = Section::create(['board_id' => $board->id, 'name' => 'To Do']);
    $card = Card::create(['board_id' => $board->id, 'section_id' => $section->id, 'name' => 'Empty', 'description' => '']);

    app(AiAssistService::class)->run($board->id, $card->id, 'req', 'rewrite', []);

    Http::assertNothingSent();
});
