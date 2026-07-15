<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cached sum of the card's payment ledger (card_payments), in the board's
        // currency. Single source of truth is the ledger; this column is recomputed
        // on every payment change so CRM readouts + milestone thresholds stay cheap.
        Schema::table('cards', function (Blueprint $table) {
            $table->decimal('amount_paid', 12, 2)->default(0)->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
    }
};
