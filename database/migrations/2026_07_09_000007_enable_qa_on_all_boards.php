<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Turn Sentinel (QA) on for every existing board.
        DB::table('boards')->update(['qa_enabled' => true]);

        // Default new boards to enabled at the DB level too (Postgres). SQLite — used only
        // in tests — doesn't support ALTER COLUMN … SET DEFAULT; board creation sets
        // qa_enabled explicitly (see BoardModelRepository), so new boards are enabled there.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE boards ALTER COLUMN qa_enabled SET DEFAULT true');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE boards ALTER COLUMN qa_enabled SET DEFAULT false');
        }
    }
};
