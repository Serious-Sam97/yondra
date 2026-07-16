<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // Issuer (emitter) details stamped onto every generated nota fiscal /
            // invoice for this board (YON-68): { name, tax_id, address, email, phone,
            // footer }. Null = fall back to the board name with the rest blank. This is
            // a simplified invoice document — NOT a SEFAZ-registered fiscal NF-e.
            $table->json('invoice_issuer')->nullable()->after('loss_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('invoice_issuer');
        });
    }
};
