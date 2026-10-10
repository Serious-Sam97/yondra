<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** Global, all-users mascot state (e.g. the Great Rewind counter, L-09). */
class VortexWorldState extends Model
{
    protected $table = 'vortex_world_state';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];
}
