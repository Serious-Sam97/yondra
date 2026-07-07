<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('cards', function (Blueprint $table) {
            // Per-board sequential ticket number (null for subtasks). Combined with the
            // board's ticket_prefix this renders as YON-42 (or #42 when no prefix).
            $table->unsignedInteger('ticket_number')->nullable()->after('position');
            // Safety net against concurrent creates handing out the same number.
            $table->unique(['board_id', 'ticket_number']);
        });
    }
    public function down(): void {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropUnique(['board_id', 'ticket_number']);
            $table->dropColumn('ticket_number');
        });
    }
};
