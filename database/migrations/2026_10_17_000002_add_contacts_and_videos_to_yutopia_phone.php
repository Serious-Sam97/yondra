<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The phone, round two: the people you added yourself (your contacts — by
// their user id, one-way like a phone book), and videos next to photos in the
// Gallery (the camera records now).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yutopia_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'contact_id']);
        });
        Schema::table('yutopia_photos', function (Blueprint $table) {
            $table->string('kind', 5)->default('photo'); // photo | video
            $table->string('mime', 40)->default('image/jpeg');
            $table->unsignedSmallInteger('secs')->default(0); // a video's length
        });
    }

    public function down(): void
    {
        Schema::table('yutopia_photos', function (Blueprint $table) {
            $table->dropColumn(['kind', 'mime', 'secs']);
        });
        Schema::dropIfExists('yutopia_contacts');
    }
};
