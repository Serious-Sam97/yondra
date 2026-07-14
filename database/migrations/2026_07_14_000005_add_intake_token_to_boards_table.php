<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // Unguessable per-board token for the public intake webhook
            // (JotForm → auto-create card). The token IS the credential — same
            // pattern as the QA CI hook — so external forms need no header auth.
            $table->string('intake_token', 64)->nullable()->unique()->after('whatsapp_verify_token');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('intake_token');
        });
    }
};
