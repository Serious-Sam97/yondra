<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('board_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type');
            $table->string('description');
            $table->timestamps();
            $table->foreign('board_id')->references('id')->on('boards')->onDelete('cascade');
        });
    }
    public function down(): void { Schema::dropIfExists('board_activities'); }
};
