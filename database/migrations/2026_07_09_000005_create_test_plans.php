<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Suites / test plans (per board), e.g. "Regressão v2.0". Cross-card grouping.
        Schema::create('test_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // N:N — a test case (from any card) can belong to many plans, re-executed per release.
        Schema::create('test_plan_case', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_case_id')->constrained()->cascadeOnDelete();
            $table->unique(['test_plan_id', 'test_case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_plan_case');
        Schema::dropIfExists('test_plans');
    }
};
