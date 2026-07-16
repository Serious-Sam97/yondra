<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('project_id');
        });

        // Seed a stable initial order per project (by id) so existing boards
        // don't all collapse onto position 0 when Manual sort is used.
        foreach (DB::table('boards')->distinct()->pluck('project_id') as $projectId) {
            $query = DB::table('boards')->orderBy('id');
            $projectId === null
                ? $query->whereNull('project_id')
                : $query->where('project_id', $projectId);

            foreach ($query->pluck('id') as $order => $id) {
                DB::table('boards')->where('id', $id)->update(['position' => $order]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
