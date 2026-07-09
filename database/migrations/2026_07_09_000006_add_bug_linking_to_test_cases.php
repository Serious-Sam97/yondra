<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_cases', function (Blueprint $table) {
            // The bug card auto-generated from a failed run (plain column, no DB FK so it
            // runs on SQLite; a dangling id just fails to resolve on the client).
            $table->unsignedBigInteger('bug_card_id')->nullable()->after('data_matrix');
            // Set when the linked bug is resolved (moved to Done); cleared on the next run.
            $table->boolean('awaiting_retest')->default(false)->after('bug_card_id');
        });
    }

    public function down(): void
    {
        Schema::table('test_cases', function (Blueprint $table) {
            $table->dropColumn(['bug_card_id', 'awaiting_retest']);
        });
    }
};
