<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

// A photo taken with the phone's camera in Yutopia (private disk; served through a signed URL).
class YutopiaPhoto extends Model
{
    public const QUOTA = 300; // shared/phone.ts PHOTO_QUOTA

    protected $fillable = ['user_id', 'space_id', 'path', 'w', 'h', 'bytes', 'filter', 'selfie', 'sent', 'where'];

    protected $casts = ['selfie' => 'boolean', 'sent' => 'boolean'];

    public function url(): string
    {
        // relative (signed:relative): the phone puts Yondra's API origin in front
        return URL::signedRoute('yutopia.photo', ['photo' => $this->id], absolute: false);
    }

    // as the phone reads it (times in ms)
    public function toPhone(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'w' => $this->w,
            'h' => $this->h,
            'filter' => $this->filter,
            'where' => $this->where,
            'at' => $this->created_at->getTimestampMs(),
            'selfie' => $this->selfie,
            'sent' => $this->sent,
        ];
    }
}
