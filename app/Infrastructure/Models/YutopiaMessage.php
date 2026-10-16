<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A text in a thread (user_id null: Vortex), a photo, or a call that ended.
class YutopiaMessage extends Model
{
    public const MAX_TEXT = 1000; // shared/phone.ts MAX_TEXT

    protected $fillable = ['thread_id', 'user_id', 'kind', 'body', 'photo_id', 'meta', 'reactions', 'reply_to'];

    protected $casts = ['meta' => 'array', 'reactions' => 'array'];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(YutopiaPhoto::class, 'photo_id');
    }
}
