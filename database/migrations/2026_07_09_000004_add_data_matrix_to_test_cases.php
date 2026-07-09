<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_cases', function (Blueprint $table) {
            // Data-driven matrix: { columns: string[], rows: string[][] } — variables
            // injected across multiple executions of the same scenario.
            $table->json('data_matrix')->nullable()->after('step_refs');
        });
    }

    public function down(): void
    {
        Schema::table('test_cases', function (Blueprint $table) {
            $table->dropColumn('data_matrix');
        });
    }
};
