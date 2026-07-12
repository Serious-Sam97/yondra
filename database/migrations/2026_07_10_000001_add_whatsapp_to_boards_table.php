<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // Which WhatsApp integration this board speaks: 'meta' (direct Cloud API)
            // or 'bsp' (a Business Solution Provider). Null => fall back to config default.
            $table->string('whatsapp_provider')->nullable()->after('github_webhook_secret');
            // Cloud API sender identity (Meta phone-number-id / WABA id).
            $table->string('whatsapp_phone_number_id')->nullable()->after('whatsapp_provider');
            $table->string('whatsapp_waba_id')->nullable()->after('whatsapp_phone_number_id');
            // Access token (Meta permanent token, or BSP api-key). Encrypted at the model layer.
            $table->text('whatsapp_token')->nullable()->after('whatsapp_waba_id');
            // Meta app secret — verifies inbound webhook HMAC signatures. Encrypted.
            $table->text('whatsapp_app_secret')->nullable()->after('whatsapp_token');
            // Token echoed back on the GET webhook-verification handshake.
            $table->string('whatsapp_verify_token')->nullable()->after('whatsapp_app_secret');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_provider', 'whatsapp_phone_number_id', 'whatsapp_waba_id',
                'whatsapp_token', 'whatsapp_app_secret', 'whatsapp_verify_token',
            ]);
        });
    }
};
