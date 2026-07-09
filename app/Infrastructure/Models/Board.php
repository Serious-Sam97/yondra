<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Board extends Model
{
    protected $fillable = [
        'user_id', 'project_id', 'name', 'type', 'currency', 'done_section_id', 'description', 'ticket_prefix',
        'next_ticket_number', 'background', 'default_permission', 'archived_at',
        'github_repo', 'github_token', 'github_webhook_secret',
    ];

    protected $casts = [
        'archived_at'     => 'datetime',
        'github_token'    => 'encrypted',
        'done_section_id' => 'integer',
    ];

    // Never expose the raw token to the client; capabilities are surfaced via
    // github_connected in the repository payload instead.
    protected $hidden = ['github_token'];

    /**
     * Does landing a card in this section mark it done/closed? Uses the board's
     * configured done column when set (the CRM "won" stage, or any chosen column);
     * otherwise falls back to the legacy rule of a section literally named "Done".
     */
    public function marksDone(Section $section): bool
    {
        return $this->done_section_id
            ? $section->id === $this->done_section_id
            : strtolower((string) $section->name) === 'done';
    }

    public function owner(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function project(): BelongsTo {
        return $this->belongsTo(\App\Infrastructure\Models\Project::class);
    }

    public function sections(): HasMany {
        return $this->hasMany(Section::class)->orderBy('order');
    }

    public function sprints(): HasMany {
        return $this->hasMany(Sprint::class);
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

    /**
     * True when the user owns (or co-owns) the project this board belongs to.
     * Project owners get implicit full control over every board in the project,
     * regardless of any lower-level board share they may also hold.
     */
    public function isProjectOwner(int $userId): bool {
        if (!$this->project_id) return false;
        $project = \App\Infrastructure\Models\Project::find($this->project_id);
        return $project ? $project->isOwnedBy($userId) : false;
    }

    public function isAccessibleBy(int $userId): bool {
        if ($this->user_id === $userId) return true;
        if ($this->isProjectOwner($userId)) return true;
        // Plain project members/viewers must be shared onto a board to see it.
        return $this->sharedWith()->where('users.id', $userId)->exists();
    }

    public function isWritableBy(int $userId): bool {
        if ($this->user_id === $userId) return true;
        // Check project ownership BEFORE any share: a project owner who also holds
        // a read-only board share must still be able to write.
        if ($this->isProjectOwner($userId)) return true;
        $share = $this->sharedWith()->where('users.id', $userId)->first();
        if ($share) return in_array($share->pivot->permission, ['write', 'owner'], true);
        return false;
    }

    /**
     * A board owner is the creator (user_id), a project owner, or any collaborator
     * whose share carries the 'owner' permission. Owners can manage sharing and delete.
     */
    public function isOwnedBy(int $userId): bool {
        if ($this->user_id === $userId) return true;
        if ($this->isProjectOwner($userId)) return true;
        return $this->sharedWith()
            ->where('users.id', $userId)
            ->wherePivot('permission', 'owner')
            ->exists();
    }
}
