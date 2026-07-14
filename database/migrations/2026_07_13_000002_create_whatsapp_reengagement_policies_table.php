<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-board policy for the idle-lead re-engagement ladder (card YON-62): after
// `idle_days` with no inbound reply, a daily sweep sends `template_name`, spaced by
// `retry_interval_days`, up to `max_attempts`; then the lead drops out (moved to the
// Lost stage, or archived when none is set).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_reengagement_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('idle_days')->default(30);
            $table->unsignedInteger('retry_interval_days')->default(7);
            $table->unsignedInteger('max_attempts')->default(4);
            $table->string('template_name')->nullable();
            $table->string('language')->default('en');
            // Where a dropped lead lands. No DB-level FK (mirrors boards.done_section_id) so
            // sqlite test migrations stay simple; nulled on section delete in the repository.
            $table->unsignedBigInteger('lost_section_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_reengagement_policies');
    }
};
