<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            // Where a user receives WhatsApp-channel notifications (digits, incl. country
            // code). The `whatsapp` preference toggle is the opt-in; this is the address.
            $table->string('whatsapp_number')->nullable()->after('email');
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('whatsapp_number');
        });
    }
};
