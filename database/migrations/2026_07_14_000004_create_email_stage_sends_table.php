<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit log of every stage-triggered email actually sent — lets the settings UI
        // show "last sent" and gives an outbound paper trail for client-facing mail.
        Schema::create('email_stage_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('subject');
            $table->string('status')->default('sent'); // sent | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['card_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_stage_sends');
    }
};
