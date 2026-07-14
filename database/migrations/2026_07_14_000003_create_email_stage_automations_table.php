<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-stage email template (card #53). Mirror of whatsapp_stage_automations, but
        // email templates are free-form (subject + body stored locally with {{variable}}
        // interpolation) rather than a pre-approved external template name.
        Schema::create('email_stage_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            // When a card lands in this section, send this template to the card's contact.
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->boolean('enabled')->default(true);
            // A manual safety brake — set to stop sending until a human clears it.
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->unique(['board_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_stage_automations');
    }
};
