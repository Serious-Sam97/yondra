<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** A fact Vortex learned about the user (F-02 · the dossier). The user can delete any. */
class VortexMemory extends Model
{
    protected $fillable = ['user_id', 'category', 'fact', 'source'];
}
