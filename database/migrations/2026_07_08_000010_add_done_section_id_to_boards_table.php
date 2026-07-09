<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('boards', function (Blueprint $table) {
            // The section that marks a card as done/closed. For CRM this is the "won"
            // stage; for kanban/scrum the "done" column. Null = fall back to the legacy
            // rule (a section literally named "Done"). No DB-level FK so the migration
            // runs on SQLite too; SectionRepository clears it when the section is deleted.
            $table->unsignedBigInteger('done_section_id')->nullable()->after('currency');
        });
    }
    public function down(): void {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('done_section_id');
        });
    }
};
