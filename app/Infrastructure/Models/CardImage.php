<?php
namespace App\Infrastructure\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CardImage extends Model {
    protected $fillable = ['card_id', 'user_id', 'path', 'original_name', 'mime_type', 'size', 'position'];

    // Expose a ready-to-render public URL to the client (APP_URL/storage/<path>).
    protected $appends = ['url'];

    public function getUrlAttribute(): string {
        return Storage::disk('public')->url($this->path);
    }

    public function card(): BelongsTo { return $this->belongsTo(Card::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
