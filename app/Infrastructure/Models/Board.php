<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Board extends Model
{
    protected $fillable = ['user_id', 'project_id', 'name', 'description'];

    public function owner(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function project(): BelongsTo {
        return $this->belongsTo(\App\Infrastructure\Models\Project::class);
    }

    public function sections(): HasMany {
        return $this->hasMany(Section::class)->orderBy('order');
    }

    public function cards(): HasMany {
        return $this->hasMany(Card::class);
    }

    public function tags(): HasMany {
        return $this->hasMany(Tag::class);
    }

    public function sharedWith(): BelongsToMany {
        return $this->belongsToMany(User::class, 'board_shares')->withPivot('permission');
    }

    public function isAccessibleBy(int $userId): bool {
        if ($this->user_id === $userId) return true;
        if ($this->sharedWith()->where('users.id', $userId)->exists()) return true;
        // Only project OWNERS keep implicit access to every board; plain
        // members/viewers must be shared onto a board to see it.
        if ($this->project_id) {
            $project = \App\Infrastructure\Models\Project::find($this->project_id);
            return $project ? $project->isOwnedBy($userId) : false;
        }
        return false;
    }

    public function isWritableBy(int $userId): bool {
        if ($this->user_id === $userId) return true;
        $share = $this->sharedWith()->where('users.id', $userId)->first();
        if ($share) return in_array($share->pivot->permission, ['write', 'owner'], true);
        // Project owners can write to any board; members/viewers get no
        // implicit board access.
        if ($this->project_id) {
            $project = \App\Infrastructure\Models\Project::find($this->project_id);
            return $project ? $project->isOwnedBy($userId) : false;
        }
        return false;
    }

    /**
     * A board owner is the creator (user_id) or any collaborator whose share
     * carries the 'owner' permission. Owners can manage sharing and delete.
     */
    public function isOwnedBy(int $userId): bool {
        if ($this->user_id === $userId) return true;
        return $this->sharedWith()
            ->where('users.id', $userId)
            ->wherePivot('permission', 'owner')
            ->exists();
    }
}
