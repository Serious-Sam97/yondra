<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Yutopia pets: someone's pets (up to three), across spaces. The world-server
// owns what happens to them (needs, xp, tricks…) and writes it back here;
// `seen_at` is when the needs were last settled, so time away counts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yutopia_pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('species', 16);
            $table->string('name', 20);
            $table->json('look');
            $table->json('outfit');
            $table->json('needs');
            $table->unsignedInteger('xp')->default(0);
            $table->json('tricks');
            $table->boolean('alive')->default(true);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->timestamp('born_at');
            $table->timestamp('died_at')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yutopia_pets');
    }
};
