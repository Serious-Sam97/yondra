<?php
namespace App\Infrastructure\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YondraNotification extends Model {
    protected $table = 'yondra_notifications';
    protected $fillable = ['user_id', 'board_id', 'card_id', 'message'];
    protected $casts = ['read_at' => 'datetime'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
