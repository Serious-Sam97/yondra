<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-level threads: a comment either is top-level (null) or belongs to
        // a top-level comment. Deleting the root takes its thread with it.
        Schema::table('card_comments', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('card_id')
                ->constrained('card_comments')
                ->cascadeOnDelete();
            $table->index(['card_id', 'parent_id']);
        });

        // One row per user per emoji per comment; toggled on repeat.
        Schema::create('comment_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();
            $table->unique(['card_comment_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_reactions');
        Schema::table('card_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropIndex(['card_id', 'parent_id']);
        });
    }
};
