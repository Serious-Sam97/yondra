<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Workspace omnisearch — boards + cards (deals) across every board the current
 * user can see. Powers the dashboard search bar.
 */
class SearchController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return ['boards' => [], 'cards' => []];
        }

        $userId   = (int) Auth::id();
        $boardIds = Board::whereNull('archived_at')
            ->where(function ($w) use ($userId) {
                $w->where('user_id', $userId)
                    ->orWhereHas('sharedWith', fn ($s) => $s->where('users.id', $userId))
                    ->orWhereHas('project', fn ($p) => $p
                        ->where('owner_id', $userId)
                        ->orWhereHas('members', fn ($m) => $m->where('users.id', $userId)->where('role', 'owner')));
            })
            ->pluck('id');

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $boards = Board::whereIn('id', $boardIds)
            ->where('name', 'like', $like)
            ->orderBy('name')
            ->limit(6)
            ->get(['id', 'name', 'project_id', 'type'])
            ->map(fn ($b) => [
                'id'         => $b->id,
                'name'       => $b->name,
                'project_id' => $b->project_id,
                'type'       => $b->type,
            ]);

        $numeric = ctype_digit($q) ? (int) $q : null;

        $cards = Card::whereIn('board_id', $boardIds)
            ->whereNull('archived_at')
            ->where(function ($w) use ($like, $numeric) {
                $w->where('name', 'like', $like);
                if ($numeric !== null) {
                    $w->orWhere('ticket_number', $numeric);
                }
            })
            ->with(['board:id,name,ticket_prefix,type', 'section:id,name'])
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(fn ($c) => [
                'id'         => $c->id,
                'name'       => $c->name,
                'board_id'   => $c->board_id,
                'board_name' => $c->board?->name,
                'section'    => $c->section?->name,
                'is_deal'    => $c->board?->type === 'crm',
                'ticket_key' => $this->ticketKey($c->board?->ticket_prefix, $c->ticket_number),
            ]);

        return ['boards' => $boards, 'cards' => $cards];
    }

    private function ticketKey(?string $prefix, ?int $number): string
    {
        if ($number === null) {
            return '';
        }

        return $prefix ? "{$prefix}-{$number}" : "#{$number}";
    }
}
