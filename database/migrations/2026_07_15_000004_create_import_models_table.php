<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Project-scoped JSON import models (YON-122). Each row describes how a shape
     * of arbitrary JSON maps onto cards: where the items live (item_path), whether
     * the document yields many cards or one, and a list of source→target field
     * rules (with optional transforms). Any board in the project can import using
     * one of its project's models; the two built-in shapes (flat, canvas) are not
     * rows here — they remain in code.
     */
    public function up(): void
    {
        Schema::create('import_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // 'many' = item_path points at an array of items → one card each;
            // 'one'  = the whole located node is a single card (like a canvas).
            $table->string('mode')->default('many');
            // Dot-path to the items within the JSON (e.g. "data.tickets"). Null/'' = root.
            $table->string('item_path')->nullable();
            // [{ target, source, transform? }] — see ImportModelMapper.
            $table->json('fields');
            // Optional stored sample JSON that powers the patchbay keys + live preview.
            $table->json('sample')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_models');
    }
};
