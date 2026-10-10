<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** An arcade high score (M-02). */
class VortexScore extends Model
{
    protected $fillable = ['user_id', 'board_id', 'game', 'score'];
}
