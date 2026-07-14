<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Track the re-engagement ladder per conversation: how many automated attempts have
// been sent and when the last one went out. Both reset to 0/null on any inbound reply.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->unsignedInteger('reengagement_attempts')->default(0);
            $table->timestamp('last_reengagement_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn(['reengagement_attempts', 'last_reengagement_at']);
        });
    }
};
