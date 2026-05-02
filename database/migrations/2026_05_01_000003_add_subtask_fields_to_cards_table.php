<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->foreignId('parent_card_id')->nullable()->after('board_id')->constrained('cards')->nullOnDelete();
            $table->boolean('is_done')->default(false)->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropForeign(['parent_card_id']);
            $table->dropColumn(['parent_card_id', 'is_done']);
        });
    }
};
