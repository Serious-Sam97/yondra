<?php

namespace App\Infrastructure\Models;

use App\Jobs\SendStageWhatsappJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
    protected static function booted(): void
    {
        // Any path that moves a card into a new section (drag-reorder or edit) fires
        // this. The queued job is a cheap no-op unless a stage automation is configured.
        static::updated(function (Card $card): void {
            if ($card->wasChanged('section_id')) {
                SendStageWhatsappJob::dispatch((int) $card->id, (int) $card->section_id);
            }
        });
    }

    protected $fillable = [
        'board_id', 'section_id', 'assigned_user_id', 'created_by_user_id',
        'name', 'description', 'due_date', 'due_reminder_sent_at', 'priority', 'position', 'archived_at', 'done_at',
        'parent_card_id', 'is_done', 'ticket_number',
        'value', 'story_points', 'sprint_id', 'section_entered_at',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'due_reminder_sent_at' => 'datetime',
        'archived_at' => 'datetime',
        'done_at' => 'datetime',
        'section_entered_at' => 'datetime',
        'is_done' => 'boolean',
        'value' => 'decimal:2',
        'story_points' => 'integer',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'card_tag');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(CardChecklistItem::class)->orderBy('position');
    }

    public function images(): HasMany
    {
        return $this->hasMany(CardImage::class)->orderBy('position');
    }

    public function links(): HasMany
    {
        return $this->hasMany(CardLink::class)->latest();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CardDocument::class)->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CardComment::class)->latest();
    }

    public function whatsappConversations(): HasMany
    {
        return $this->hasMany(WhatsappConversation::class);
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Card::class, 'parent_card_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'parent_card_id');
    }
}
