<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Board extends Model
{
    protected $fillable = ['user_id', 'name', 'description'];

    public function owner(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sections(): HasMany {
        return $this->hasMany(Section::class);
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
        return $this->user_id === $userId
            || $this->sharedWith()->where('users.id', $userId)->exists();
    }

    public function isWritableBy(int $userId): bool {
        if ($this->user_id === $userId) return true;
        $share = $this->sharedWith()->where('users.id', $userId)->first();
        return $share && $share->pivot->permission === 'write';
    }
}
