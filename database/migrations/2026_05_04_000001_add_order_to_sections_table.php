<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->unsignedInteger('order')->default(0)->after('name');
        });

        // Seed existing sections with their current implicit order (id order per board)
        DB::statement('
            UPDATE sections s
            JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY board_id ORDER BY id) - 1 AS rn
                FROM sections
            ) ranked ON s.id = ranked.id
            SET s.order = ranked.rn
        ');
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }
};
