<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            // 'in' (from the customer) or 'out' (sent from Yondra).
            $table->string('direction', 3);
            // Meta's message id (wamid...). Used to reconcile status webhooks.
            $table->string('wa_message_id')->nullable();
            $table->string('type')->default('text');
            $table->text('body')->nullable();
            // received | sent | delivered | read | failed.
            $table->string('status')->nullable();
            // For outbound template sends: the approved template name used.
            $table->string('template_name')->nullable();
            $table->string('error')->nullable();
            // The Yondra user who sent an outbound message (null for automations/inbound).
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('wa_message_id');
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('whatsapp_messages');
    }
};
