<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** A reminder Vortex will deliver (G-10). */
class VortexReminder extends Model
{
    protected $fillable = ['user_id', 'body', 'remind_at', 'delivered_at'];

    protected $casts = ['remind_at' => 'datetime', 'delivered_at' => 'datetime'];
}
