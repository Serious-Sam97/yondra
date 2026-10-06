<?php

namespace App\Infrastructure\Models;

use App\Observers\CardRelatedObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([CardRelatedObserver::class])]
class TestRun extends Model
{
    protected $fillable = [
        'test_case_id', 'board_id', 'status', 'executor_user_id',
        'environment', 'device', 'executed_at', 'evidence', 'logs',
        'items', 'source',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'evidence' => 'array',
        'items' => 'array', // [{ block_key, block_title, ok, evidence, bug_card_id, lines }]
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
