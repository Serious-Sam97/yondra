<?php
namespace App\Infrastructure\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardChecklistItem extends Model {
    protected $fillable = ['card_id', 'text', 'is_done', 'position'];
    protected $casts = ['is_done' => 'boolean'];
    public function card(): BelongsTo { return $this->belongsTo(Card::class); }
}
