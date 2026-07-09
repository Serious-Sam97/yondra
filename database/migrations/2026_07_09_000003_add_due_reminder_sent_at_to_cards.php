<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            // When the "due soon" reminder was last sent, so the scheduler notifies
            // once per due window. Cleared when the due date changes (re-arms it).
            $table->timestamp('due_reminder_sent_at')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('due_reminder_sent_at');
        });
    }
};
