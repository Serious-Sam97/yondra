<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single global AI settings row (see the create_ai_settings migration). Holds the ordered
 * `chain` (fallback order, by instance id) and the non-secret per-instance knobs in `instances`
 * — a provider type plus its own model/base_url/reasoning. `providers` is the legacy per-type map,
 * kept for backward-compat reads. API keys live in env, never here. Read/merged with config
 * defaults by App\Services\Ai\AiSettingsResolver.
 */
class AiSetting extends Model
{
    protected $table = 'ai_settings';

    protected $fillable = [
        'max_tokens',
        'chain',
        'providers',
        'instances',
        'balance_enabled',
        'balance_timeout',
    ];

    protected $casts = [
        'max_tokens' => 'integer',
        'chain' => 'array',
        'providers' => 'array',
        'instances' => 'array',
        'balance_enabled' => 'boolean',
        'balance_timeout' => 'integer',
    ];

    /** The one global settings row (the migration seeds it). */
    public static function current(): ?self
    {
        return static::query()->orderBy('id')->first();
    }
}
