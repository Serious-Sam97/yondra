<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class YutopiaPet extends Model
{
    public const MAX_PER_USER = 3;

    protected $fillable = ['user_id', 'species', 'name', 'look', 'outfit', 'needs', 'xp', 'tricks', 'traits', 'memory', 'bonds', 'alive', 'wins', 'losses', 'born_at', 'died_at', 'seen_at'];

    protected $casts = [
        'look' => 'array',
        'outfit' => 'array',
        'needs' => 'array',
        'tricks' => 'array',
        'traits' => 'array',
        'memory' => 'array',
        'bonds' => 'array',
        'alive' => 'boolean',
        'born_at' => 'datetime',
        'died_at' => 'datetime',
        'seen_at' => 'datetime',
    ];

    // As the world-server reads it (shared/pets.ts PetRecord; times in ms).
    public function toWorld(): array
    {
        return [
            'id' => $this->id,
            'species' => $this->species,
            'name' => $this->name,
            'look' => $this->look,
            'outfit' => $this->outfit,
            'needs' => $this->needs,
            'xp' => $this->xp,
            'tricks' => (object) ($this->tricks ?? []),
            'traits' => $this->traits ? (object) $this->traits : null,
            'memory' => $this->memory ? (object) $this->memory : null,
            'bonds' => (object) ($this->bonds ?? []),
            'alive' => $this->alive,
            'bornAt' => $this->born_at->getTimestampMs(),
            'diedAt' => $this->died_at?->getTimestampMs(),
            'seenAt' => $this->seen_at->getTimestampMs(),
            'wins' => $this->wins,
            'losses' => $this->losses,
        ];
    }
}
