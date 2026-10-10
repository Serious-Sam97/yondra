<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Q-03 · real push (works with the tab closed): one row per browser that said yes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vortex_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('p256dh', 200);
            $table->string('auth', 100);
            $table->timestamps();
        });
        Schema::table('vortex_reminders', function (Blueprint $table) {
            $table->timestamp('pushed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vortex_push_subscriptions');
        Schema::table('vortex_reminders', function (Blueprint $table) {
            $table->dropColumn('pushed_at');
        });
    }
};
