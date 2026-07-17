<?php

namespace App\Http\Controllers\Vortex;

use App\Http\Controllers\Controller;
use App\Infrastructure\Models\ErrorGroup;
use Illuminate\Http\Request;

/**
 * Vortex "Anomalies" API (YON-74) — read + triage the error groups the
 * ErrorRecorder collects. Admin-only (mounted behind auth:sanctum + vortex.admin).
 */
class ErrorController extends Controller
{
    private const SORTS = [
        'last_seen' => 'last_seen_at',
        'first_seen' => 'first_seen_at',
        'count' => 'occurrences_count',
    ];

    /** Paginated, filterable list of error groups. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => 'sometimes|in:open,resolved,ignored,all',
            'source' => 'sometimes|in:backend,frontend,all',
            'q' => 'sometimes|string|max:200',
            'sort' => 'sometimes|in:last_seen,first_seen,count',
            'dir' => 'sometimes|in:asc,desc',
            'per_page' => 'sometimes|integer|min:1|max:200',
        ]);

        $query = ErrorGroup::query();

        $status = $data['status'] ?? 'open';
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $source = $data['source'] ?? 'all';
        if ($source !== 'all') {
            $query->where('source', $source);
        }

        if (($data['q'] ?? '') !== '') {
            $q = mb_strtolower($data['q']);
            $query->where(function ($w) use ($q) {
                $w->whereRaw('LOWER(exception_class) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(message) LIKE ?', ["%{$q}%"]);
            });
        }

        $sort = self::SORTS[$data['sort'] ?? 'last_seen'];
        $dir = $data['dir'] ?? 'desc';

        return response()->json(
            $query->orderBy($sort, $dir)->paginate((int) ($data['per_page'] ?? 25))
        );
    }

    /** Headline counts for the panel's status pills. */
    public function stats()
    {
        return response()->json([
            'open' => ErrorGroup::where('status', 'open')->count(),
            'resolved' => ErrorGroup::where('status', 'resolved')->count(),
            'ignored' => ErrorGroup::where('status', 'ignored')->count(),
            'total' => ErrorGroup::count(),
            'last_24h' => ErrorGroup::where('last_seen_at', '>=', now()->subDay())
                ->sum('occurrences_count'),
        ]);
    }

    /** One group with its most-recent occurrences (newest first). */
    public function show(int $id)
    {
        $group = ErrorGroup::findOrFail($id);

        $occurrences = $group->occurrences()
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(50)->get();

        return response()->json([
            'group' => $group,
            'occurrences' => $occurrences,
        ]);
    }

    public function resolve(int $id)
    {
        return $this->transition($id, 'resolved');
    }

    public function ignore(int $id)
    {
        return $this->transition($id, 'ignored');
    }

    public function reopen(int $id)
    {
        return $this->transition($id, 'open');
    }

    public function destroy(int $id)
    {
        ErrorGroup::findOrFail($id)->delete(); // occurrences cascade

        return response()->noContent();
    }

    /** Bulk-clear every resolved group (cascades their occurrences). */
    public function clearResolved()
    {
        $deleted = ErrorGroup::where('status', 'resolved')->get()
            ->each->delete()->count();

        return response()->json(['deleted' => $deleted]);
    }

    private function transition(int $id, string $status)
    {
        $group = ErrorGroup::findOrFail($id);
        $group->status = $status;
        $group->resolved_at = $status === 'resolved' ? now() : null;
        $group->save();

        return response()->json(['group' => $group->fresh()]);
    }
}
