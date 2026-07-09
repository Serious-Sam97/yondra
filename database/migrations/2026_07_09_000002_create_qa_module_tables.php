<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-board toggle for the Sentinel QA module.
        Schema::table('boards', function (Blueprint $table) {
            $table->boolean('qa_enabled')->default(false)->after('done_section_id');
        });

        // A card owns N test cases (the living documentation of a test).
        Schema::create('test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type')->default('manual'); // manual|automated|performance|security
            $table->foreignId('qa_planner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_env')->nullable();
            $table->text('gherkin')->nullable();
            $table->text('preconditions')->nullable();
            $table->text('postconditions')->nullable();
            $table->unsignedInteger('position')->default(0);
            // Lightweight audit: version counter + who last edited (full history is Phase 2).
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Each test case owns N runs (reports). Append-only — a run is never edited.
        Schema::create('test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('status'); // passed|failed|blocked
            $table->foreignId('executor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('environment')->nullable();
            $table->string('device')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('evidence')->nullable(); // [{url, kind}]
            $table->text('logs')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_runs');
        Schema::dropIfExists('test_cases');
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('qa_enabled');
        });
    }
};
