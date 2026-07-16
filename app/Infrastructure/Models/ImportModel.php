<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project-scoped custom JSON import model (YON-122): a reusable recipe for
 * turning a particular shape of JSON into cards. `fields` is the mapping —
 * [{ target, source, transform? }] — applied by App\Services\CardImport\ImportModelMapper.
 */
class ImportModel extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'mode',
        'item_path',
        'fields',
        'sample',
        'created_by',
    ];

    protected $casts = [
        'fields' => 'array',
        'sample' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
