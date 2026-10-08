<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sticky notes users leave for a teammate "from Vortex" on a board. The
 * recipient's Vortex delivers it the next time they open that board.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vortex_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('body', 200);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['board_id', 'to_user_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vortex_notes');
    }
};
