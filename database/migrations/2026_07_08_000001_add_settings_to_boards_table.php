<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // Board accent/theme key (an LED accent from the Cassette-Futurism set).
            $table->string('background', 40)->nullable()->after('next_ticket_number');
            // Default share permission handed to newly-invited members.
            $table->string('default_permission', 10)->default('write')->after('background');
            // Soft-close: an archived board is hidden from the main lists but recoverable.
            $table->timestamp('archived_at')->nullable()->after('default_permission');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['background', 'default_permission', 'archived_at']);
        });
    }
};
