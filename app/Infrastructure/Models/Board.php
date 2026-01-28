<?php

namespace App\Infrastructure\Models;

use Card;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Board extends Model
{
    public function cards(): HasMany {
        return $this->hasMany(Card::class);
    }
}
