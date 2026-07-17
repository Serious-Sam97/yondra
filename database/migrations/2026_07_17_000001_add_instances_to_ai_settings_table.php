<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AI manager moved from one slot per provider TYPE to N named INSTANCES (a provider type
     * plus its own model/base_url/reasoning). `instances` holds that ordered list; `chain` now
     * references instance ids. The legacy `providers` map is kept for backward-compat reads — a
     * row saved before this migration still resolves (AiSettingsResolver projects it into one
     * instance per type). Nullable + NOT seeded: with no row / no instances, the resolver falls
     * back to config defaults, so behaviour is unchanged until the admin saves in Vortex.
     */
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            // [ {id, provider, label, enabled, model, base_url, reasoning_effort}, ... ]
            // Non-secret only — api keys stay in env, shared across a type's instances.
            $table->json('instances')->nullable()->after('providers');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('instances');
        });
    }
};
