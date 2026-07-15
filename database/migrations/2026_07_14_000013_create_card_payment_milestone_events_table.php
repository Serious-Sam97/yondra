<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit log + idempotency ledger for milestone firings (YON-63). One row per
        // (card, milestone) records that the threshold was crossed and what happened —
        // the unique index guarantees a milestone fires exactly once per card, even
        // under concurrent payment jobs. Mirrors email_stage_sends' audit precedent.
        Schema::create('card_payment_milestone_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            // Keep the row (and its history) if the rule is later deleted.
            $table->foreignId('milestone_id')->nullable()->constrained('payment_milestones')->nullOnDelete();
            $table->unsignedSmallInteger('threshold_pct');
            $table->decimal('amount_paid_at_trigger', 12, 2)->default(0);

            // Outcome of the message action.
            $table->string('message_status')->default('none'); // none | sent | failed | skipped
            $table->string('message_channel')->nullable();      // whatsapp | email
            $table->text('error')->nullable();

            // Outcome of the move action.
            $table->foreignId('moved_to_section_id')->nullable()->constrained('sections')->nullOnDelete();

            $table->timestamp('triggered_at')->nullable();
            $table->timestamps();

            $table->unique(['card_id', 'milestone_id']);
            $table->index(['card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_payment_milestone_events');
    }
};
