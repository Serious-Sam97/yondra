<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-space map layouts (size, walls/floors, rooms). Null = the built-in map.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yutopia_spaces', function (Blueprint $table) {
            $table->json('layout')->nullable()->after('map_key');
        });
    }

    public function down(): void
    {
        Schema::table('yutopia_spaces', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
