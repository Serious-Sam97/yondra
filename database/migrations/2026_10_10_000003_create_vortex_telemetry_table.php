<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// T-07 · consented, aggregate-only telemetry: one row per (day, event), no user id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vortex_telemetry', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('event', 40);
            $table->unsignedInteger('shown')->default(0);
            $table->unsignedInteger('fast')->default(0);
            $table->unsignedInteger('clicked')->default(0);
            $table->unique(['day', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vortex_telemetry');
    }
};
