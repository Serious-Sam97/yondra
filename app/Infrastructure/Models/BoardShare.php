<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class BoardShare extends Model
{
    protected $fillable = ['board_id', 'user_id', 'permission'];

    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
