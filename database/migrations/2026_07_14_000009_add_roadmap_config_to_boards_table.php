<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // YON-120: manager-defined flowchart for the Roadmap view. Maps each
            // column (section) to a positioned step node, plus the directed edges
            // between steps: { nodes: [{section_id,x,y}], edges: [{from,to}] }.
            // Null = auto-layout (linear chain from section order).
            $table->json('roadmap_config')->nullable()->after('intake_field_map');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('roadmap_config');
        });
    }
};
