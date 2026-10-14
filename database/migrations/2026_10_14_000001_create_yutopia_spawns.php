<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where each person arrives in a space, when they picked a spot of their own
// (no row: the space's arrival point, set by builders in the layout).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yutopia_spawns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('yutopia_spaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->float('x');
            $table->float('y');
            $table->timestamps();
            $table->unique(['space_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yutopia_spawns');
    }
};
