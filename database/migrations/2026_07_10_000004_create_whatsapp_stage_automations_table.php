<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_stage_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            // When a card lands in this section, send the mapped template.
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            // A pre-approved Utility template (required: business-initiated messages).
            $table->string('template_name');
            $table->string('language')->default('en');
            $table->boolean('enabled')->default(true);
            // Set when quality drops (yellow/red) — a safety brake, cleared manually.
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->unique(['board_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_stage_automations');
    }
};
