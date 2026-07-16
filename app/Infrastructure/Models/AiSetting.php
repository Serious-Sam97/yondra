<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single global AI settings row (see the create_ai_settings migration). Holds the
 * ordered provider `chain` (fallback order) and the non-secret per-provider knobs in
 * `providers`. API keys live in env, never here. Read/merged with config defaults by
 * App\Services\Ai\AiSettingsResolver.
 */
class AiSetting extends Model
{
    protected $table = 'ai_settings';

    protected $fillable = [
        'max_tokens',
        'chain',
        'providers',
        'balance_enabled',
        'balance_timeout',
    ];

    protected $casts = [
        'max_tokens' => 'integer',
        'chain' => 'array',
        'providers' => 'array',
        'balance_enabled' => 'boolean',
        'balance_timeout' => 'integer',
    ];

    /** The one global settings row (the migration seeds it). */
    public static function current(): ?self
    {
        return static::query()->orderBy('id')->first();
    }
}
