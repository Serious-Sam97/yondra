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
        $boardIds = DB::table('sections')->distinct()->pluck('board_id');
        foreach ($boardIds as $boardId) {
            $ids = DB::table('sections')
                ->where('board_id', $boardId)
                ->orderBy('id')
                ->pluck('id');
            foreach ($ids as $index => $id) {
                DB::table('sections')->where('id', $id)->update(['order' => $index]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }
};
