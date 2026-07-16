<?php

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

/** A CRM board + one stage whose cards drive the deals export. */
function crmExportBoard(User $owner, string $currency = 'USD'): array
{
    $board = Board::create([
        'user_id' => $owner->id, 'name' => 'Pipeline', 'description' => '',
        'type' => 'crm', 'currency' => $currency, 'ticket_prefix' => 'YON',
    ]);
    $stage = Section::create(['board_id' => $board->id, 'name' => 'Lead In', 'order' => 0]);

    return [$board, $stage];
}

it('exports one row per deal with the full column set', function () {
    $owner = User::factory()->create();
    [$board, $stage] = crmExportBoard($owner);
    $contact = Contact::create(['board_id' => $board->id, 'name' => 'Acme Corp']);

    Card::create([
        'board_id' => $board->id, 'section_id' => $stage->id, 'name' => 'Website build',
        'description' => '', 'position' => 0, 'ticket_number' => 7, 'contact_id' => $contact->id,
        'value' => 5000, 'amount_paid' => 2500, 'priority' => 'high',
        'done_at' => now()->toDateTimeString(),
    ]);

    $res = $this->actingAs($owner)->getJson('/api/reports/deals')->assertOk();

    expect($res->json('count'))->toBe(1);
    expect($res->json('currency'))->toBe('USD');
    expect($res->json('total_value'))->toEqual(5000.0);
    expect($res->json('total_paid'))->toEqual(2500.0);

    $row = $res->json('rows.0');
    expect($row['ticket'])->toBe('YON-7');
    expect($row['deal'])->toBe('Website build');
    expect($row['client'])->toBe('Acme Corp');
    expect($row['stage'])->toBe('Lead In');
    expect($row['status'])->toBe('Won');
    expect($row['value'])->toEqual(5000.0);
    expect($row['paid'])->toEqual(2500.0);
    expect($row['priority'])->toBe('High');

    // The column spec is echoed so the client renders header order from the API.
    $keys = collect($res->json('columns'))->pluck('key');
    expect($keys)->toContain('deal', 'client', 'value', 'status', 'closed');
});

it('filters by status and windows on the matching date', function () {
    $owner = User::factory()->create();
    [$board, $stage] = crmExportBoard($owner);

    $base = ['board_id' => $board->id, 'section_id' => $stage->id, 'description' => '', 'position' => 0];
    Card::create($base + ['name' => 'Won deal', 'value' => 100, 'done_at' => now()->toDateTimeString()]);
    Card::create($base + ['name' => 'Lost deal', 'value' => 200, 'lost_at' => now()->toDateTimeString(), 'loss_reason' => 'Budget']);
    Card::create($base + ['name' => 'Open deal', 'value' => 300]);

    // all → three rows
    $all = $this->actingAs($owner)->getJson('/api/reports/deals?status=all')->assertOk();
    expect($all->json('count'))->toBe(3);

    // won → only the won deal
    $won = $this->actingAs($owner)->getJson('/api/reports/deals?status=won')->assertOk();
    expect($won->json('count'))->toBe(1);
    expect($won->json('rows.0.deal'))->toBe('Won deal');

    // lost → only the lost deal, with its reason
    $lost = $this->actingAs($owner)->getJson('/api/reports/deals?status=lost')->assertOk();
    expect($lost->json('count'))->toBe(1);
    expect($lost->json('rows.0.loss_reason'))->toBe('Budget');

    // open → only the open deal
    $open = $this->actingAs($owner)->getJson('/api/reports/deals?status=open')->assertOk();
    expect($open->json('count'))->toBe(1);
    expect($open->json('rows.0.status'))->toBe('Open');
});

it('excludes won deals closed outside the selected month window', function () {
    $owner = User::factory()->create();
    [$board, $stage] = crmExportBoard($owner);
    $base = ['board_id' => $board->id, 'section_id' => $stage->id, 'description' => '', 'position' => 0];

    Card::create($base + ['name' => 'This month', 'value' => 100, 'done_at' => now()->toDateTimeString()]);
    Card::create($base + ['name' => 'Long ago', 'value' => 999, 'done_at' => now()->subMonths(6)->toDateTimeString()]);

    $month = now()->format('Y-m');
    $res = $this->actingAs($owner)
        ->getJson("/api/reports/deals?status=won&from={$month}&to={$month}")
        ->assertOk();

    expect($res->json('count'))->toBe(1);
    expect($res->json('rows.0.deal'))->toBe('This month');
});

it('answers a CSV download when format=csv', function () {
    $owner = User::factory()->create();
    [$board, $stage] = crmExportBoard($owner);
    Card::create([
        'board_id' => $board->id, 'section_id' => $stage->id, 'name' => 'CSV deal',
        'description' => '', 'position' => 0, 'value' => 1200, 'done_at' => now()->toDateTimeString(),
    ]);

    $res = $this->actingAs($owner)->get('/api/reports/deals?format=csv')->assertOk();
    $res->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($res->headers->get('content-disposition'))->toContain('attachment');

    $body = $res->streamedContent();
    expect($body)->toContain('Deal'); // header label
    expect($body)->toContain('CSV deal');
    expect($body)->toContain('1200.00');
});

it('scopes to accessible CRM boards and honours board_id', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    [$mine, $myStage] = crmExportBoard($owner);
    [$theirs, $theirStage] = crmExportBoard($stranger);

    Card::create(['board_id' => $mine->id, 'section_id' => $myStage->id, 'name' => 'Mine', 'description' => '', 'position' => 0, 'value' => 10, 'done_at' => now()->toDateTimeString()]);
    Card::create(['board_id' => $theirs->id, 'section_id' => $theirStage->id, 'name' => 'Theirs', 'description' => '', 'position' => 0, 'value' => 20, 'done_at' => now()->toDateTimeString()]);

    // Owner only sees their own board's deal.
    $res = $this->actingAs($owner)->getJson('/api/reports/deals')->assertOk();
    expect($res->json('count'))->toBe(1);
    expect($res->json('rows.0.deal'))->toBe('Mine');

    // Asking for someone else's board id yields an empty export, not a leak.
    $leak = $this->actingAs($owner)->getJson("/api/reports/deals?board_id={$theirs->id}")->assertOk();
    expect($leak->json('count'))->toBe(0);
});

it('flags a mixed-currency export and drops the single currency', function () {
    $owner = User::factory()->create();
    [$usd, $usdStage] = crmExportBoard($owner, 'USD');
    [$eur, $eurStage] = crmExportBoard($owner, 'EUR');

    Card::create(['board_id' => $usd->id, 'section_id' => $usdStage->id, 'name' => 'USD deal', 'description' => '', 'position' => 0, 'value' => 100, 'done_at' => now()->toDateTimeString()]);
    Card::create(['board_id' => $eur->id, 'section_id' => $eurStage->id, 'name' => 'EUR deal', 'description' => '', 'position' => 0, 'value' => 200, 'done_at' => now()->toDateTimeString()]);

    $res = $this->actingAs($owner)->getJson('/api/reports/deals')->assertOk();
    expect($res->json('count'))->toBe(2);
    expect($res->json('multi_currency'))->toBeTrue();
    expect($res->json('currency'))->toBeNull();
});
