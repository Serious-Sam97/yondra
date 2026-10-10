<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vortex MK-V (design/vortex-mk5): the mascot's server-side life.
 * - vortex_souls: his whole state per user (needs, mood, relation, corruption,
 *   lore, inventory…) as JSON, plus the indexed columns the hourly life tick needs.
 * - vortex_memories: the dossier — facts he learned about the user (F-02), deletable.
 * - vortex_journal: his diary (C-12), letters (F-14) and other daily writing.
 * - vortex_ledger: every coin earned/spent, auditable (N-03).
 * - vortex_scores: arcade high scores per board (M-02).
 * - vortex_reminders: reminders he delivers (G-10).
 * - vortex_world_state: global, all-users state (the Great Rewind L-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vortex_souls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('state');
            $table->smallInteger('relation')->default(0);
            $table->unsignedTinyInteger('corruption')->default(0);
            $table->unsignedTinyInteger('proximity')->default(0);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_tick_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vortex_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 24);
            $table->string('fact', 240);
            $table->string('source', 24)->default('chat');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('vortex_journal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // diary | letter | dream | podcast | gazette
            $table->date('day');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'day']);
        });

        Schema::create('vortex_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 12); // tokens | minutes | echoes
            $table->integer('amount');
            $table->string('reason', 48);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'currency']);
        });

        Schema::create('vortex_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('game', 24);
            $table->unsignedInteger('score');
            $table->timestamps();
            $table->index(['game', 'board_id', 'score']);
        });

        Schema::create('vortex_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body', 240);
            $table->timestamp('remind_at')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vortex_world_state', function (Blueprint $table) {
            $table->string('key', 48)->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vortex_world_state');
        Schema::dropIfExists('vortex_reminders');
        Schema::dropIfExists('vortex_scores');
        Schema::dropIfExists('vortex_ledger');
        Schema::dropIfExists('vortex_journal');
        Schema::dropIfExists('vortex_memories');
        Schema::dropIfExists('vortex_souls');
    }
};
