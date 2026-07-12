<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // Optional short key shown before the number, e.g. "YON" -> YON-42.
            $table->string('ticket_prefix', 10)->nullable()->after('description');
            // Per-board counter for the next ticket number to hand out.
            $table->unsignedInteger('next_ticket_number')->default(1)->after('ticket_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['ticket_prefix', 'next_ticket_number']);
        });
    }
};
