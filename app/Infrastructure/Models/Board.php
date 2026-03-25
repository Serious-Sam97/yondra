<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Board extends Model
{
    public function sections(): HasMany {
        return $this->hasMany(Section::class);
    }

    public function cards(): HasMany {
        return $this->hasMany(Card::class);
    }
}
