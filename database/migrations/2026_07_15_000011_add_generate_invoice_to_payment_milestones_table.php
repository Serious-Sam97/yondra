<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_milestones', function (Blueprint $table) {
            // Invoice action (YON-68): when this milestone fires (e.g. at 100% paid),
            // issue a nota fiscal / invoice document for the card and attach it. Sits
            // alongside the existing notify + move-to-stage actions.
            $table->boolean('generate_invoice')->default(false)->after('move_to_section_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_milestones', function (Blueprint $table) {
            $table->dropColumn('generate_invoice');
        });
    }
};
