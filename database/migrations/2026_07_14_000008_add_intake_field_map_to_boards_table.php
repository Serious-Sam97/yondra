<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // YON-50: manager-configured [{source,target}] rules mapping form-field
            // labels onto card attributes (title/value/tags/…). Null = pure heuristics.
            $table->json('intake_field_map')->nullable()->after('intake_token');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('intake_field_map');
        });
    }
};
