<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Yutopia's phone (yutopia/docs/plan/21-cellphone.md): someone's phone
// settings, threads (one-to-one, groups, a space's own thread, Vortex) with
// their members and texts, and the photos taken with its camera. Calls live in
// the world-server; each one ends up here as a "call" message in its thread.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yutopia_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('settings');
            $table->timestamps();
        });

        Schema::create('yutopia_threads', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // direct | group | space | vortex
            $table->string('name', 60)->nullable();
            $table->foreignId('space_id')->nullable()->constrained('yutopia_spaces')->cascadeOnDelete();
            $table->string('direct_key', 40)->nullable()->unique(); // "a:b" (a < b), "vortex:a"
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'space_id']);
        });

        Schema::create('yutopia_thread_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('yutopia_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->boolean('muted')->default(false);
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->unique(['thread_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('yutopia_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('yutopia_spaces')->nullOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('w');
            $table->unsignedSmallInteger('h');
            $table->unsignedInteger('bytes');
            $table->string('filter', 10)->default('none');
            $table->boolean('selfie')->default(false);
            $table->boolean('sent')->default(false);
            $table->string('where', 120)->default('');
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });

        Schema::create('yutopia_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('yutopia_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null: Vortex
            $table->string('kind', 8)->default('text'); // text | photo | call | system
            $table->text('body')->nullable();
            $table->foreignId('photo_id')->nullable()->constrained('yutopia_photos')->nullOnDelete();
            $table->json('meta')->nullable(); // call: end, secs, video, from
            $table->json('reactions')->nullable(); // emote → user ids
            $table->unsignedBigInteger('reply_to')->nullable();
            $table->timestamps();
            $table->index(['thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yutopia_messages');
        Schema::dropIfExists('yutopia_photos');
        Schema::dropIfExists('yutopia_thread_members');
        Schema::dropIfExists('yutopia_threads');
        Schema::dropIfExists('yutopia_phones');
    }
};
