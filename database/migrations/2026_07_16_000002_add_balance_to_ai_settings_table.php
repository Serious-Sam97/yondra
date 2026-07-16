<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional latency load-balancing for the AI chain: when `balance_enabled`, each
     * non-final provider gets `balance_timeout` seconds to start responding; if it's too
     * slow, the request aborts and falls through to the next provider. Off by default —
     * the chain then only fails over on an actual error, exactly as before.
     */
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->boolean('balance_enabled')->default(false);
            $table->unsignedSmallInteger('balance_timeout')->default(5);
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['balance_enabled', 'balance_timeout']);
        });
    }
};
