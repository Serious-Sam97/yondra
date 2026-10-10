<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YutopiaObject extends Model
{
    protected $fillable = ['space_id', 'uid', 'kind', 'x', 'y', 'rot', 'ref_id', 'props'];

    protected $casts = ['props' => 'array', 'x' => 'integer', 'y' => 'integer', 'rot' => 'integer', 'ref_id' => 'integer'];

    public function space(): BelongsTo
    {
        return $this->belongsTo(YutopiaSpace::class, 'space_id');
    }

    public function toWorld(): array
    {
        return [
            'id' => $this->uid,
            'kind' => $this->kind,
            'x' => $this->x,
            'y' => $this->y,
            'rot' => $this->rot,
            'refId' => $this->ref_id,
            'props' => $this->props ?? (object) [],
        ];
    }
}
