<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Companion to 2026_07_01_000001: Postgres does not auto-index foreign key
// columns, and the QA (Sentinel), sprint, planning-poker and test-plan tables
// all landed after that migration ran. Covers the per-card QA lookups, sprint
// scoping and the dashboard/report done_at scans.
//
// Deliberately skipped: columns already covered by a unique constraint or a
// composite index whose leading column matches (e.g. card_links.card_id,
// test_plan_case.test_plan_id, whatsapp_conversations.board_id,
// whatsapp_messages.conversation_id), and rarely-queried nullable audit
// columns such as *_by_user_id / executor_user_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_cases', function (Blueprint $table) {
            $table->index('board_id');
            $table->index('card_id');
            $table->index('bug_card_id');
        });

        Schema::table('test_runs', function (Blueprint $table) {
            $table->index('board_id');
            $table->index('test_case_id');
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->index('sprint_id');
            $table->index('done_at');
        });

        Schema::table('planning_sessions', function (Blueprint $table) {
            $table->index('board_id');
        });

        Schema::table('reusable_steps', function (Blueprint $table) {
            $table->index('board_id');
        });

        Schema::table('test_plans', function (Blueprint $table) {
            $table->index('board_id');
        });

        Schema::table('test_plan_case', function (Blueprint $table) {
            // test_plan_id is the leading column of the unique pair; the reverse
            // lookup (cases -> plans) needs its own index.
            $table->index('test_case_id');
        });
    }

    public function down(): void
    {
        Schema::table('test_cases', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
            $table->dropIndex(['card_id']);
            $table->dropIndex(['bug_card_id']);
        });

        Schema::table('test_runs', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
            $table->dropIndex(['test_case_id']);
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['sprint_id']);
            $table->dropIndex(['done_at']);
        });

        Schema::table('planning_sessions', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
        });

        Schema::table('reusable_steps', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
        });

        Schema::table('test_plans', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
        });

        Schema::table('test_plan_case', function (Blueprint $table) {
            $table->dropIndex(['test_case_id']);
        });
    }
};
