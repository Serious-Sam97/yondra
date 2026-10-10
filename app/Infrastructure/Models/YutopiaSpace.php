<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class YutopiaSpace extends Model
{
    protected $fillable = ['project_id', 'slug', 'name', 'map_key', 'layout', 'settings', 'seeded_at'];

    protected $casts = ['settings' => 'array', 'layout' => 'array', 'seeded_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function objects(): HasMany
    {
        return $this->hasMany(YutopiaObject::class, 'space_id');
    }

    public function desks(): HasMany
    {
        return $this->hasMany(YutopiaDesk::class, 'space_id');
    }

    public function isAccessibleBy(int $userId): bool
    {
        return $this->project?->isAccessibleBy($userId) ?? false;
    }

    // Owners (and co-owners) of the project may rearrange the room in build mode.
    public function isBuildableBy(int $userId): bool
    {
        return $this->project?->isOwnedBy($userId) ?? false;
    }
}
