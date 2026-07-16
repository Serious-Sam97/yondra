<?php

use App\Infrastructure\Models\Board;
use App\Services\TagService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            // 'channel' tags are seeded per board (WhatsApp/Email/Phone/Instagram)
            // with locked names; 'custom' tags are the free-form ones users create.
            $table->string('kind', 16)->default('custom')->after('color');
        });

        // Backfill the canonical channel tags onto every existing board so the
        // Channel/Custom split is populated retroactively (idempotent per board).
        $service = resolve(TagService::class);
        foreach (Board::query()->pluck('id') as $boardId) {
            $service->seedChannelTags($boardId);
        }
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
