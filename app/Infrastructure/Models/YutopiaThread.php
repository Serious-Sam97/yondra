<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A conversation on Yutopia's phone: one-to-one ("direct"), a group, a space's
// own thread (everyone who can open the space is in it), or Vortex.
class YutopiaThread extends Model
{
    public const KINDS = ['direct', 'group', 'space', 'vortex'];

    protected $fillable = ['kind', 'name', 'space_id', 'direct_key', 'created_by', 'last_message_at'];

    protected $casts = ['last_message_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(YutopiaThreadMember::class, 'thread_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(YutopiaMessage::class, 'thread_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(YutopiaSpace::class, 'space_id');
    }
}
