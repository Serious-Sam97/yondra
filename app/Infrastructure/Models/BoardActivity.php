<?php
namespace App\Infrastructure\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardActivity extends Model {
    protected $fillable = ['board_id', 'user_id', 'type', 'description'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
