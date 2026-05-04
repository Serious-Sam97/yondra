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
        if ($this->project_id) {
            return \App\Infrastructure\Models\Project::where('id', $this->project_id)
                ->whereHas('members', fn($q) => $q->where('users.id', $userId))
                ->exists();
        }
        return false;
    }

    public function isWritableBy(int $userId): bool {
        if ($this->user_id === $userId) return true;
        $share = $this->sharedWith()->where('users.id', $userId)->first();
        if ($share) return $share->pivot->permission === 'write';
        // Project members can write; viewers can only read
        if ($this->project_id) {
            $pivot = \App\Infrastructure\Models\Project::find($this->project_id)
                ?->members()->where('users.id', $userId)->first()?->pivot;
            return $pivot && $pivot->role === 'member';
        }
        return false;
    }
}
