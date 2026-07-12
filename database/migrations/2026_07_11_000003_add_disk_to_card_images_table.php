<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Card attachment images move to private storage served via signed URLs.
// Existing rows default to 'public' (their /storage URLs are already in the
// wild and keep working); new uploads are written to the 'local' disk. Run
// `php artisan yondra:privatize-images` to migrate old files off the public
// disk — the move is deliberately NOT done in a migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_images', function (Blueprint $table) {
            $table->string('disk')->default('public')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('card_images', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
