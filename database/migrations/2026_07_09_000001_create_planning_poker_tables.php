<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // One live Planning Poker session per card (Scrum estimation).
        Schema::create('planning_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round')->default(1);
            $table->boolean('revealed')->default(false);
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A participant's vote for a given round. A row with a null value = joined,
        // not yet voted. value is a Fibonacci number or '?' (kept as text for '?').
        Schema::create('planning_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round')->default(1);
            $table->string('value')->nullable();
            $table->timestamp('voted_at')->nullable();
            $table->timestamps();
            $table->unique(['planning_session_id', 'user_id', 'round']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('planning_votes');
        Schema::dropIfExists('planning_sessions');
    }
};
