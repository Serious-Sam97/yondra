<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReusableStep extends Model
{
    protected $fillable = ['board_id', 'title', 'content', 'gherkin_lines'];

    protected $casts = [
        'gherkin_lines' => 'array', // [{ keyword, text }]
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }
}
