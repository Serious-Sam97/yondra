<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Yutopia — the isometric world synced with Yondra. Laravel only stores the
// durable bits (spaces, placed objects, avatars, desks); positions and who-is-where
// live in the world-server and the cache.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yutopia_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('map_key')->default('studio');
            $table->json('settings')->nullable();
            $table->timestamp('seeded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('yutopia_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('yutopia_spaces')->cascadeOnDelete();
            $table->string('uid', 64);
            $table->string('kind', 40);
            $table->integer('x');
            $table->integer('y');
            $table->unsignedTinyInteger('rot')->default(0);
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->json('props')->nullable();
            $table->timestamps();
            $table->unique(['space_id', 'uid']);
        });

        Schema::create('yutopia_avatars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('layers');
            $table->timestamps();
        });

        Schema::create('yutopia_desks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('yutopia_spaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('desk_uid', 64);
            $table->timestamps();
            $table->unique(['space_id', 'user_id']);
            $table->unique(['space_id', 'desk_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yutopia_desks');
        Schema::dropIfExists('yutopia_avatars');
        Schema::dropIfExists('yutopia_objects');
        Schema::dropIfExists('yutopia_spaces');
    }
};
