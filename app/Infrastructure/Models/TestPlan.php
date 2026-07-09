<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TestPlan extends Model
{
    protected $fillable = ['board_id', 'name', 'description'];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function cases(): BelongsToMany
    {
        return $this->belongsToMany(TestCase::class, 'test_plan_case');
    }
}
