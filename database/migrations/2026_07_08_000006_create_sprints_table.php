<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Only one sprint per board is the "active" one shown on the Scrum board.
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index('board_id');
        });
    }
    public function down(): void {
        Schema::dropIfExists('sprints');
    }
};
