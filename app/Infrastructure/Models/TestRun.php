<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestRun extends Model
{
    protected $fillable = [
        'test_case_id', 'board_id', 'status', 'executor_user_id',
        'environment', 'device', 'executed_at', 'evidence', 'logs',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'evidence' => 'array',
    ];

    public function testCase(): BelongsTo
    {
        return $this->belongsTo(TestCase::class);
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executor_user_id');
    }
}
