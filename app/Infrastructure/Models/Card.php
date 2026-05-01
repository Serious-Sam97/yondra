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
        'name', 'description', 'due_date', 'priority', 'position',
    ];

    protected $casts = ['due_date' => 'date:Y-m-d'];

    public function assignedUser(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function createdBy(): BelongsTo      { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function tags(): BelongsToMany       { return $this->belongsToMany(Tag::class, 'card_tag'); }
    public function checklistItems(): HasMany   { return $this->hasMany(CardChecklistItem::class)->orderBy('position'); }
    public function comments(): HasMany         { return $this->hasMany(CardComment::class)->latest(); }
}
