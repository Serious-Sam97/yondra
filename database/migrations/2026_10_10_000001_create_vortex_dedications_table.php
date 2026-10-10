<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// O-06 · dedications on the ghost radio: one teammate to another, read on air.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vortex_dedications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('text', 160);
            $table->timestamp('aired_at')->nullable();
            $table->timestamps();
            $table->index(['to_user_id', 'aired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vortex_dedications');
    }
};
