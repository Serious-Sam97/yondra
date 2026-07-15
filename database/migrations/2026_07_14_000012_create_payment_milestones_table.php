<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-board payment milestone rules (YON-63). When a card's paid % crosses a
        // milestone's threshold, its actions fire: send a message (WhatsApp/email) and/or
        // move the card to a target section (e.g. the "invoice" stage at 100%).
        Schema::create('payment_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('threshold_pct'); // 1..100
            $table->string('label')->nullable();

            // Message action.
            $table->boolean('notify')->default(false);
            $table->string('channel')->default('auto'); // auto | whatsapp | email
            $table->string('whatsapp_template_name')->nullable();
            $table->string('language')->default('en');
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();

            // Stage-move action (null = don't move). e.g. the invoice stage at 100%.
            $table->foreignId('move_to_section_id')->nullable()->constrained('sections')->nullOnDelete();

            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // One rule per threshold per board.
            $table->unique(['board_id', 'threshold_pct']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_milestones');
    }
};
