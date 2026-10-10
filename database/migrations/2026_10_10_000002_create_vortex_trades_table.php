<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// N-14 · item trades between teammates: one offers, the other accepts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vortex_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('give', 48)->nullable();
            $table->unsignedSmallInteger('give_tokens')->default(0);
            $table->string('want', 48)->nullable();
            $table->unsignedSmallInteger('want_tokens')->default(0);
            $table->string('status', 12)->default('open'); // open | done | declined | void
            $table->timestamps();
            $table->index(['to_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vortex_trades');
    }
};
