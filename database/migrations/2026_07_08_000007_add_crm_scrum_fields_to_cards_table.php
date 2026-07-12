<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            // CRM: monetary deal value (board currency).
            $table->decimal('value', 12, 2)->nullable()->after('priority');
            // When the card entered its current section — drives per-stage SLA aging.
            $table->timestamp('section_entered_at')->nullable()->after('done_at');
            // Scrum: effort estimate and sprint assignment.
            $table->unsignedSmallInteger('story_points')->nullable()->after('value');
            $table->foreignId('sprint_id')->nullable()->after('story_points')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sprint_id');
            $table->dropColumn(['value', 'section_entered_at', 'story_points']);
        });
    }
};
