<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attachment on a card (PDF, Office doc, text, zip…). Unlike CardImage
 * these live on the PRIVATE ('local') disk and are never exposed by a public
 * URL — the client downloads them through an auth-gated route that re-checks
 * board access (see CardDocumentController::download).
 */
class CardDocument extends Model
{
    protected $fillable = ['card_id', 'user_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'position'];

    protected $casts = ['size' => 'integer'];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
