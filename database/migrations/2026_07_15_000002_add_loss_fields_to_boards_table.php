<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // The CRM "lost" stage — the mirror of done_section_id/"won" (YON-66).
            // Moving a deal into it requires a loss reason. Null = fall back to a
            // section literally named "Lost". No DB-level FK so it runs on SQLite;
            // SectionRepository clears it when the section is deleted.
            $table->unsignedBigInteger('lost_section_id')->nullable()->after('done_section_id');
            // Per-board editable list of loss reasons (string[]), required on lost.
            $table->json('loss_reasons')->nullable()->after('lost_section_id');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['lost_section_id', 'loss_reasons']);
        });
    }
};
