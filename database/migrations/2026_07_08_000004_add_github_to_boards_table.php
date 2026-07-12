<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // "owner/repo" this board's cards link into.
            $table->string('github_repo')->nullable()->after('default_permission');
            // Encrypted at the model layer (cast). Never returned to the client.
            $table->text('github_token')->nullable()->after('github_repo');
            // Per-board secret used to verify inbound GitHub webhook signatures.
            $table->string('github_webhook_secret')->nullable()->after('github_token');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['github_repo', 'github_token', 'github_webhook_secret']);
        });
    }
};
