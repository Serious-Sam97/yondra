<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sections', function (Blueprint $table) {
            // Per-stage SLA threshold (hours). A card sitting longer than this in the
            // stage "rots" (turns red). Null = no aging for this stage.
            $table->unsignedInteger('aging_hours')->nullable()->after('order');
        });
    }
    public function down(): void {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('aging_hours');
        });
    }
};
