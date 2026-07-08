<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('projects', function (Blueprint $table) {
            // Default board share permission inherited by boards created in this project.
            $table->string('default_permission', 10)->default('write')->after('color');
            // Soft-close: an archived project is hidden from the main lists but recoverable.
            $table->timestamp('archived_at')->nullable()->after('default_permission');
        });
    }
    public function down(): void {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['default_permission', 'archived_at']);
        });
    }
};
