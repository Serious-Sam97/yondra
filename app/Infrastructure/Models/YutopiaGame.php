<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

// A cartridge a team uploaded to its Yutopia space (see the game room plan).
class YutopiaGame extends Model
{
    protected $fillable = ['space_id', 'uploaded_by', 'system', 'title', 'color', 'players', 'file_name', 'path', 'bytes', 'sha1'];

    protected $hidden = ['path'];
}
