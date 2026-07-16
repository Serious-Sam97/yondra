<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository\Concerns;

use App\Infrastructure\Models\Board;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Shared board scope for the CRM reports (revenue YON-64, conversion YON-65):
 * every non-archived CRM board the current user can see (owns / shared with /
 * project-owner). Kept in one place so the visibility rule can't drift between
 * reports.
 */
trait ResolvesCrmBoards
{
    protected function accessibleCrmBoards(): Collection
    {
        $userId = (int) Auth::id();

        return Board::whereNull('archived_at')
            ->where('type', 'crm')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereHas('sharedWith', fn ($s) => $s->where('users.id', $userId))
                    ->orWhereHas('project', fn ($p) => $p
                        ->where('owner_id', $userId)
                        ->orWhereHas('members', fn ($m) => $m->where('users.id', $userId)->where('role', 'owner')));
            })
            ->get(['id', 'currency']);
    }
}
