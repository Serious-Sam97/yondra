<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['owner_id', 'name', 'description', 'color', 'default_permission', 'archived_at'];

    protected $casts = ['archived_at' => 'datetime'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function boards(): HasMany
    {
        return $this->hasMany(Board::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user')->withPivot('role')->withTimestamps();
    }

    public function isAccessibleBy(int $userId): bool
    {
        return $this->owner_id === $userId
            || $this->members()->where('users.id', $userId)->exists();
    }

    public function isOwnedBy(int $userId): bool
    {
        if ($this->owner_id === $userId) {
            return true;
        }

        // Co-owners are project members carrying the 'owner' pivot role.
        return $this->members()
            ->where('users.id', $userId)
            ->wherePivot('role', 'owner')
            ->exists();
    }
}
