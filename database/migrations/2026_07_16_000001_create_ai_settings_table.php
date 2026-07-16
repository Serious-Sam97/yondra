<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global AI settings — a single row that lets the Vortex admin control which LLM
     * provider(s) the API uses at runtime, with no worker restart. `chain` is the ordered
     * list of provider keys tried in turn (fallback), `providers` holds the non-secret
     * per-provider knobs (model / base_url / reasoning_effort / enabled). API keys are
     * NEVER stored here — they stay in env and are read straight from config.
     *
     * Intentionally NOT seeded: with no row, AiSettingsResolver falls back to pure config
     * defaults (chain = [AI_DRIVER]), so behaviour is byte-for-byte the pre-feature setup
     * until the admin saves settings in Vortex — which creates the row and takes over.
     */
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('max_tokens')->default(700);
            // Ordered provider keys tried in turn, e.g. ["ollama","anthropic"].
            $table->json('chain');
            // { anthropic:{enabled,model,base_url,reasoning_effort}, groq:{...}, ollama:{...} }
            // Non-secret only — no api_key ever.
            $table->json('providers');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
