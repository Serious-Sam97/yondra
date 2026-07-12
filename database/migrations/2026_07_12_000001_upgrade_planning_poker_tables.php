<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Presence: participants heartbeat while their tab is open; rows that go
        // silent without a cast vote are pruned from the round.
        Schema::table('planning_votes', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('voted_at');
        });

        // When an estimate was last committed to the card — lets clients react to
        // the apply *event* instead of diffing story_points.
        Schema::table('planning_sessions', function (Blueprint $table) {
            $table->timestamp('applied_at')->nullable()->after('revealed');
        });
    }

    public function down(): void
    {
        Schema::table('planning_votes', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
        Schema::table('planning_sessions', function (Blueprint $table) {
            $table->dropColumn('applied_at');
        });
    }
};
