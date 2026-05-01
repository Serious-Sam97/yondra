<?php
namespace App\Infrastructure\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardComment extends Model {
    protected $fillable = ['card_id', 'user_id', 'body'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function card(): BelongsTo { return $this->belongsTo(Card::class); }
}
