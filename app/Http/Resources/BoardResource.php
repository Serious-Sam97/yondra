<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Infrastructure\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

/**
 * Full board serialization for GET /boards/{id} — the shape the board page is
 * typed against. Starts from the model's own array form (the model's $hidden
 * keeps the raw github/whatsapp secrets out) and layers on the computed
 * fields the client needs:
 *
 * - can_write / can_manage: the current user's capabilities, so the client can
 *   gate editing/managing without re-deriving the (project-aware) rules.
 * - github_connected / whatsapp_connected: whether a token is set (never the
 *   token itself).
 * - github_webhook_secret / whatsapp_verify_token: only useful to — and only
 *   shown to — managers setting up the webhooks; nulled for everyone else.
 * - ticket_key on every nested card ("YON-42" / "#42").
 */
class BoardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $board = $this->resource;
        $userId = (int) Auth::id();
        $canWrite = $board->isWritableBy($userId);
        $canManage = $board->isOwnedBy($userId);

        // Flatten each collaborator's pivot permission onto the user payload.
        $board->sharedWith->each(fn ($u) => $u->permission = $u->pivot->permission ?? 'write');

        $data = $board->toArray();

        $data['can_write'] = $canWrite;
        $data['can_manage'] = $canManage;
        $data['github_connected'] = filled($board->github_token);
        $data['whatsapp_connected'] = filled($board->whatsapp_token);
        $data['intake_connected'] = filled($board->intake_token);
        if (! $canManage) {
            $data['github_webhook_secret'] = null;
            $data['whatsapp_verify_token'] = null;
            // The intake token is a live credential (embedded in the webhook URL);
            // only managers configuring the integration ever see it.
            $data['intake_token'] = null;
        }

        if ($board->relationLoaded('cards')) {
            $data['cards'] = $board->cards
                ->map(fn (Card $card) => CardResource::withTicketKeyFromPrefix($card, $board->ticket_prefix)->resolve($request))
                ->all();
        }

        return $data;
    }
}
