<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Jammed" (blocked) cards on the board: when a card is blocked, and why.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('section_entered_at');
            $table->string('blocked_reason', 160)->nullable()->after('blocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'blocked_reason']);
        });
    }
};
