<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            // The client/lead this card represents. Nulls out (not cascades) if the
            // contact is deleted so the card itself survives.
            $table->foreignId('contact_id')->nullable()->after('assigned_user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });
    }
};
