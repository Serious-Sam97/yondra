<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('card_id');
            // Denormalized so webhooks can locate affected links without a card join.
            $table->unsignedBigInteger('board_id');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('provider', 20)->default('github');
            $table->string('type', 10)->default('pr');       // pr | issue
            $table->string('url');
            $table->string('owner')->nullable();
            $table->string('repo')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('title')->nullable();
            $table->string('state', 20)->nullable();          // open | closed | merged | draft
            $table->boolean('merged')->default(false);
            $table->string('checks_state', 20)->nullable();   // success | failure | pending
            $table->string('author')->nullable();
            $table->string('html_url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('card_id')->references('id')->on('cards')->onDelete('cascade');
            $table->foreign('board_id')->references('id')->on('boards')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['card_id', 'url']);
            $table->index(['board_id', 'owner', 'repo', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_links');
    }
};
