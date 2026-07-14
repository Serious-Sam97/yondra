<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Board extends Model
{
    protected $fillable = [
        'user_id', 'project_id', 'name', 'type', 'currency', 'done_section_id', 'qa_enabled', 'description', 'ticket_prefix',
        'next_ticket_number', 'background', 'default_permission', 'archived_at',
        'github_repo', 'github_token', 'github_webhook_secret',
        'whatsapp_provider', 'whatsapp_phone_number_id', 'whatsapp_waba_id',
        'whatsapp_token', 'whatsapp_app_secret', 'whatsapp_verify_token',
        'intake_token', 'intake_field_map', 'email_spam_safe', 'require_optin_before_email',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
        'github_token' => 'encrypted',
        'whatsapp_token' => 'encrypted',
        'whatsapp_app_secret' => 'encrypted',
        'done_section_id' => 'integer',
        'qa_enabled' => 'boolean',
        'email_spam_safe' => 'boolean',
        'require_optin_before_email' => 'boolean',
        'intake_field_map' => 'array',
    ];

    // Never expose raw secrets to the client; capabilities are surfaced via
    // *_connected flags in the repository payload instead.
    protected $hidden = ['github_token', 'whatsapp_token', 'whatsapp_app_secret'];

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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('order');
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'board_shares')->withPivot('permission');
    }

    /**
     * True when the user owns (or co-owns) the project this board belongs to.
     * Project owners get implicit full control over every board in the project,
     * regardless of any lower-level board share they may also hold.
     */
    public function isProjectOwner(int $userId): bool
    {
        if (! $this->project_id) {
            return false;
        }
        $project = Project::find($this->project_id);

        return $project ? $project->isOwnedBy($userId) : false;
    }

    public function isAccessibleBy(int $userId): bool
    {
        if ($this->user_id === $userId) {
            return true;
        }
        if ($this->isProjectOwner($userId)) {
            return true;
        }

        // Plain project members/viewers must be shared onto a board to see it.
        return $this->sharedWith()->where('users.id', $userId)->exists();
    }

    public function isWritableBy(int $userId): bool
    {
        if ($this->user_id === $userId) {
            return true;
        }
        // Check project ownership BEFORE any share: a project owner who also holds
        // a read-only board share must still be able to write.
        if ($this->isProjectOwner($userId)) {
            return true;
        }
        $share = $this->sharedWith()->where('users.id', $userId)->first();
        if ($share) {
            return in_array($share->pivot->permission, ['write', 'owner'], true);
        }

        return false;
    }

    /**
     * A board owner is the creator (user_id), a project owner, or any collaborator
     * whose share carries the 'owner' permission. Owners can manage sharing and delete.
     */
    public function isOwnedBy(int $userId): bool
    {
        if ($this->user_id === $userId) {
            return true;
        }
        if ($this->isProjectOwner($userId)) {
            return true;
        }

        return $this->sharedWith()
            ->where('users.id', $userId)
            ->wherePivot('permission', 'owner')
            ->exists();
    }
}
