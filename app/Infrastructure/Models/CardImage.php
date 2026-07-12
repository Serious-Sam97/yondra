<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class CardImage extends Model
{
    protected $fillable = ['card_id', 'user_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'position'];

    // Expose a ready-to-render URL to the client — always a plain string the
    // frontend can drop straight into an <img src>.
    protected $appends = ['url'];

    // Internal storage detail; hidden so the REST payload shape is unchanged.
    protected $hidden = ['disk'];

    public function getUrlAttribute(): string
    {
        // Compatibility: rows still on the public disk (pre-privatization uploads,
        // until `yondra:privatize-images` moves them) keep their APP_URL/storage
        // URL — those links are already in the wild. Private rows get a
        // time-limited signed streaming URL instead: <img> tags cannot send auth
        // headers, so possession of the (expiring, regenerated on every payload)
        // link is the access capability. The signature is relative so validation
        // does not depend on which host the API is reached through.
        if (($this->disk ?: 'public') === 'public') {
            return Storage::disk('public')->url($this->path);
        }

        return rtrim(config('app.url'), '/').URL::temporarySignedRoute(
            'card-images.show',
            now()->addWeek(),
            ['imageId' => $this->id],
            absolute: false,
        );
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
