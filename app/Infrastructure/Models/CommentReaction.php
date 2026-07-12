<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReaction extends Model
{
    protected $fillable = ['card_comment_id', 'user_id', 'emoji'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(CardComment::class, 'card_comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
