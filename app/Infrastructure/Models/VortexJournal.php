<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** Something Vortex wrote: a diary entry, a letter, a dream, a podcast script… */
class VortexJournal extends Model
{
    protected $table = 'vortex_journal';

    protected $fillable = ['user_id', 'kind', 'day', 'body', 'read_at'];

    protected $casts = ['day' => 'date', 'read_at' => 'datetime'];
}
