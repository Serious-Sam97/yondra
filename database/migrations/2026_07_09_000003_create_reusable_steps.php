<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global (per-board) reusable test steps. A test case references these by id,
        // so editing a step here propagates to every case that uses it.
        Schema::create('reusable_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content')->nullable();
            $table->timestamps();
        });

        // step_refs = [{ step_id, overrides? }] — resolved against the library at render.
        Schema::table('test_cases', function (Blueprint $table) {
            $table->json('step_refs')->nullable()->after('postconditions');
        });
    }

    public function down(): void
    {
        Schema::table('test_cases', function (Blueprint $table) {
            $table->dropColumn('step_refs');
        });
        Schema::dropIfExists('reusable_steps');
    }
};
