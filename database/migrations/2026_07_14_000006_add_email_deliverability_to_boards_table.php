<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // YON-51: naturalize stage-email copy (drop currency symbols / soften quote
            // keywords) so Gmail stops spam-filtering quotes. On by default — pure win.
            $table->boolean('email_spam_safe')->default(true)->after('intake_token');
            // YON-52: only send stage (quote) emails to contacts who confirmed via the
            // opt-in link. Off by default so existing boards keep sending unchanged.
            $table->boolean('require_optin_before_email')->default(false)->after('email_spam_safe');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['email_spam_safe', 'require_optin_before_email']);
        });
    }
};
