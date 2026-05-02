<?php
namespace App\Infrastructure\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardMessage extends Model {
    protected $fillable = ['board_id', 'user_id', 'body'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function board(): BelongsTo { return $this->belongsTo(Board::class); }
}
