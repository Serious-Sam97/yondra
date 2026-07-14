<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // YON-52 double opt-in: the submitter clicks the link in the confirmation
            // email, which whitelists the sender so future quotes reach the inbox.
            // The unguessable token is the credential for the public confirm endpoint.
            $table->string('confirm_token', 64)->nullable()->unique()->after('phone');
            $table->timestamp('confirmed_at')->nullable()->after('confirm_token');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['confirm_token', 'confirmed_at']);
        });
    }
};
