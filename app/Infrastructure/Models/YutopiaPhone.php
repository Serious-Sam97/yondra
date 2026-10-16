<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

// Someone's phone in Yutopia: the case, wallpapers, ringtones… (shared/phone.ts PhoneSettings).
class YutopiaPhone extends Model
{
    protected $fillable = ['user_id', 'settings'];

    protected $casts = ['settings' => 'array'];
}
