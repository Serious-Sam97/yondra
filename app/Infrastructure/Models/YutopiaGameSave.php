<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

// Where a game played in a Yutopia space left off: its battery save and a save state.
class YutopiaGameSave extends Model
{
    protected $fillable = ['space_id', 'game', 'state_path', 'sram_path', 'updated_by'];
}
