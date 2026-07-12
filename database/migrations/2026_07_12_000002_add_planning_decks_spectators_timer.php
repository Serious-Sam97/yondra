<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_sessions', function (Blueprint $table) {
            // Card deck for the session, fixed at creation ('fib', 'fib-x', 'tshirt').
            $table->string('deck', 20)->default('fib')->after('revealed');
            // Soft voting deadline — when passed, the round auto-reveals.
            $table->timestamp('timer_ends_at')->nullable()->after('deck');
        });

        Schema::table('planning_votes', function (Blueprint $table) {
            // Spectators sit at the table without a hand (PO watching, stakeholder).
            $table->boolean('is_spectator')->default(false)->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('planning_sessions', function (Blueprint $table) {
            $table->dropColumn(['deck', 'timer_ends_at']);
        });
        Schema::table('planning_votes', function (Blueprint $table) {
            $table->dropColumn('is_spectator');
        });
    }
};
