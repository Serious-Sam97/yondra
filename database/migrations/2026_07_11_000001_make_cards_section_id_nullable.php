<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deleting a section now archives its cards instead of hard-deleting them
// (the only irreversible delete in the app). Archived survivors are detached
// from the dead section (section_id = NULL) and re-homed to the board's first
// section on restore — so the column must accept NULL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->unsignedBigInteger('section_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->unsignedBigInteger('section_id')->nullable(false)->change();
        });
    }
};
