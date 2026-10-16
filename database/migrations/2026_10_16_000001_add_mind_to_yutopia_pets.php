<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A pet's mind (yutopia docs/plan/17-pets-minds.md): who it is (traits), what
// it remembers (memory) and who it's close to (bonds, by user id). The
// world-server owns them; null until it has written them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yutopia_pets', function (Blueprint $table) {
            $table->json('traits')->nullable()->after('tricks');
            $table->json('memory')->nullable()->after('traits');
            $table->json('bonds')->nullable()->after('memory');
        });
    }

    public function down(): void
    {
        Schema::table('yutopia_pets', function (Blueprint $table) {
            $table->dropColumn(['traits', 'memory', 'bonds']);
        });
    }
};
