<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
    protected $fillable = [
        'board_id', 'section_id', 'assigned_user_id', 'created_by_user_id',
        'name', 'description', 'due_date', 'priority', 'position', 'archived_at', 'done_at',
        'parent_card_id', 'is_done',
    ];

    protected $casts = [
        'due_date'    => 'date:Y-m-d',
        'archived_at' => 'datetime',
        'done_at'     => 'datetime',
        'is_done'     => 'boolean',
    ];

    public function assignedUser(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function createdBy(): BelongsTo      { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function tags(): BelongsToMany       { return $this->belongsToMany(Tag::class, 'card_tag'); }
    public function checklistItems(): HasMany   { return $this->hasMany(CardChecklistItem::class)->orderBy('position'); }
    public function comments(): HasMany         { return $this->hasMany(CardComment::class)->latest(); }
    public function subtasks(): HasMany         { return $this->hasMany(Card::class, 'parent_card_id'); }
    public function parent(): BelongsTo         { return $this->belongsTo(Card::class, 'parent_card_id'); }
}
