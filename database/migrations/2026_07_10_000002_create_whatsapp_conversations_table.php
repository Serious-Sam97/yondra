<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            // The Yondra card this thread lives on (a lead/contact). Nullable so a
            // conversation can exist before it's attached to a card.
            $table->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            // The customer's WhatsApp number (wa_id, digits only, incl. country code).
            $table->string('wa_phone');
            $table->string('contact_name')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            // When the free 24h customer-service window closes. Past => template required.
            $table->timestamp('service_window_expires_at')->nullable();
            // Number quality as last reported by Meta: green | yellow | red.
            $table->string('quality_state')->nullable();
            $table->timestamps();

            // One thread per (board, number).
            $table->unique(['board_id', 'wa_phone']);
            $table->index('card_id');
        });
    }

    public function down(): void {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
