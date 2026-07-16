<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            // Mirrors done_at/"won": stamped when a deal enters the Lost stage (YON-66),
            // cleared when it leaves. Feeds the loss report's month buckets.
            $table->timestamp('lost_at')->nullable()->after('done_at');
            // The reason chosen from the board's configured loss_reasons list.
            $table->string('loss_reason')->nullable()->after('lost_at');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['lost_at', 'loss_reason']);
        });
    }
};
