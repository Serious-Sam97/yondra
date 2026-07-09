<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's standard morphable notifications table. Replaces the flat
        // `yondra_notifications` table so we get first-class multi-channel
        // Notification classes (database now, mail/push later).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Backfill existing bell history so users don't lose their notifications
        // in the cut-over. Each legacy row becomes a database notification whose
        // `data` matches the shape the API/frontend now expects.
        if (Schema::hasTable('yondra_notifications')) {
            foreach (DB::table('yondra_notifications')->orderBy('id')->cursor() as $old) {
                $deepLink = $old->board_id
                    ? '/boards/'.$old->board_id.($old->card_id ? '?card='.$old->card_id : '')
                    : null;

                DB::table('notifications')->insert([
                    'id' => (string) Str::uuid(),
                    'type' => 'App\\Notifications\\LegacyNotification',
                    'notifiable_type' => 'App\\Infrastructure\\Models\\User',
                    'notifiable_id' => $old->user_id,
                    'data' => json_encode([
                        'type' => 'legacy',
                        'message' => $old->message,
                        'board_id' => $old->board_id,
                        'card_id' => $old->card_id,
                        'deep_link' => $deepLink,
                    ]),
                    'read_at' => $old->read_at,
                    'created_at' => $old->created_at,
                    'updated_at' => $old->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
