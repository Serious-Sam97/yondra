<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-card history (the History tab in the card modal). One row per action on a
     * card or anything hanging off it. Distinct from board_activities, which stays the
     * thin dashboard feed. Labels (column/user/tag names) are snapshotted into
     * `changes`/`meta` at write time so history reads correctly after renames/deletes.
     */
    public function up(): void
    {
        Schema::create('card_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            // Null = no human actor (webhook, automation, CI).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // user | webhook | automation | ci | import | planning
            $table->string('source', 32)->default('user');
            $table->string('type', 64);
            // { field: { from, to } } — see App\Services\CardHistory.
            $table->json('changes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['card_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_activities');
    }
};
