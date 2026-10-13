<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Yutopia "my setups": a group of things someone saved in build mode (a
// dressed desk, a corner of the lounge) to place again anywhere. Per person,
// across spaces. Items are kinds with offsets from the group's corner.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yutopia_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->json('items');
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yutopia_setups');
    }
};
