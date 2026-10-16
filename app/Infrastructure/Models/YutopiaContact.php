<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

// Someone in a person's phone book on Yutopia's phone (added by their user id).
class YutopiaContact extends Model
{
    public const MAX = 500;

    protected $fillable = ['user_id', 'contact_id'];
}
