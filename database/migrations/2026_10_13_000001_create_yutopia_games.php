<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Yutopia's game room: the cartridges a team uploads to its space (ROMs they
// own; the built-in ones ship with Yutopia's client), and the saves of every
// game played there (battery save + a save state, per space and game).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yutopia_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('yutopia_spaces')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('system', 8);
            $table->string('title', 60);
            $table->string('color', 7);
            $table->unsignedTinyInteger('players')->default(1);
            $table->string('file_name', 120);
            $table->string('path');
            $table->unsignedInteger('bytes');
            $table->char('sha1', 40);
            $table->timestamps();
            $table->index('space_id');
        });

        Schema::create('yutopia_game_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('yutopia_spaces')->cascadeOnDelete();
            $table->string('game', 40); // "b:<slug>" built-in, "u:<id>" uploaded
            $table->string('state_path')->nullable();
            $table->string('sram_path')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['space_id', 'game']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yutopia_game_saves');
        Schema::dropIfExists('yutopia_games');
    }
};
