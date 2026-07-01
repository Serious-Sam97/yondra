<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Postgres does not auto-index foreign key columns; these cover the lookups
// every board/card/notification request performs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->index('board_id');
            $table->index('section_id');
            $table->index('parent_card_id');
            $table->index('assigned_user_id');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->index('board_id');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->index('board_id');
        });

        Schema::table('card_checklist_items', function (Blueprint $table) {
            $table->index('card_id');
        });

        Schema::table('card_comments', function (Blueprint $table) {
            $table->index('card_id');
        });

        Schema::table('yondra_notifications', function (Blueprint $table) {
            $table->index(['user_id', 'read_at']);
        });

        Schema::table('board_activities', function (Blueprint $table) {
            $table->index('board_id');
        });

        Schema::table('board_messages', function (Blueprint $table) {
            $table->index('board_id');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
            $table->dropIndex(['section_id']);
            $table->dropIndex(['parent_card_id']);
            $table->dropIndex(['assigned_user_id']);
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
        });

        Schema::table('card_checklist_items', function (Blueprint $table) {
            $table->dropIndex(['card_id']);
        });

        Schema::table('card_comments', function (Blueprint $table) {
            $table->dropIndex(['card_id']);
        });

        Schema::table('yondra_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'read_at']);
        });

        Schema::table('board_activities', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
        });

        Schema::table('board_messages', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
        });
    }
};
