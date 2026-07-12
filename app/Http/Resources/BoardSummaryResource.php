<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Board serialization for list/summary responses (index, store, update,
 * duplicate) — boards without their nested sections/cards tree. The base form
 * is the model's own array (the model's $hidden keeps raw secrets out); the
 * opt-in extras mirror exactly what each endpoint historically appended.
 */
class BoardSummaryResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly bool $withSharePermissions = false,
        private readonly bool $withConnectionFlags = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $board = $this->resource;

        // Flatten each collaborator's pivot permission onto the user payload
        // (the boards index is what the share dialog reads permissions from).
        if ($this->withSharePermissions && $board->relationLoaded('sharedWith')) {
            $board->sharedWith->each(fn ($u) => $u->permission = $u->pivot->permission ?? 'write');
        }

        $data = $board->toArray();

        // Expose whether a token is set — never the token itself.
        if ($this->withConnectionFlags) {
            $data['github_connected'] = filled($board->github_token);
            $data['whatsapp_connected'] = filled($board->whatsapp_token);
        }

        return $data;
    }
}
