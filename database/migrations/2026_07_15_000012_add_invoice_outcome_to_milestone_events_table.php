<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_payment_milestone_events', function (Blueprint $table) {
            // Outcome of the invoice action (YON-68), mirroring message_status/error.
            $table->string('invoice_status')->default('none')->after('moved_to_section_id'); // none | issued | failed
            $table->unsignedInteger('invoice_number')->nullable()->after('invoice_status');
        });
    }

    public function down(): void
    {
        Schema::table('card_payment_milestone_events', function (Blueprint $table) {
            $table->dropColumn(['invoice_status', 'invoice_number']);
        });
    }
};
