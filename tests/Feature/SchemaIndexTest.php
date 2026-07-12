<?php

use Illuminate\Support\Facades\Schema;

// Regression guard for the FK/index migrations (2026_07_01 + 2026_07_11):
// Postgres does not auto-index FK columns, so these hot lookup columns must
// carry explicit indexes. hasIndex(columns) is driver-agnostic.
it('indexes the QA, sprint and planning foreign key columns', function (string $table, array $columns) {
    expect(Schema::hasIndex($table, $columns))->toBeTrue();
})->with([
    ['test_cases', ['board_id']],
    ['test_cases', ['card_id']],
    ['test_cases', ['bug_card_id']],
    ['test_runs', ['board_id']],
    ['test_runs', ['test_case_id']],
    ['cards', ['sprint_id']],
    ['cards', ['done_at']],
    ['planning_sessions', ['board_id']],
    ['reusable_steps', ['board_id']],
    ['test_plans', ['board_id']],
    ['test_plan_case', ['test_case_id']],
]);
