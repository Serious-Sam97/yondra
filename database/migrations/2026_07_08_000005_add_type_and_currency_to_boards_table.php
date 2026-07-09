<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('boards', function (Blueprint $table) {
            // Board workflow type: kanban (default), scrum, or crm (sales).
            $table->string('type', 20)->default('kanban')->after('name');
            // Currency for CRM deal values (ISO 4217). Defaults to Brazilian Real.
            $table->char('currency', 3)->default('BRL')->after('type');
        });
    }
    public function down(): void {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['type', 'currency']);
        });
    }
};
