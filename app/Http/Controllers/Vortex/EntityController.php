<?php

namespace App\Http\Controllers\Vortex;

use App\Http\Controllers\Controller;
use App\Infrastructure\Models\CardDocument;
use App\Infrastructure\Models\CardImage;
use App\Services\Vortex\EntityRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EntityController extends Controller
{
    public function index()
    {
        $entities = [];
        foreach (EntityRegistry::ENTITIES as $slug => $cfg) {
            $entities[] = [
                'slug' => $slug,
                'label' => $cfg['label'],
                'count' => $cfg['model']::count(),
                'searchable' => $cfg['searchable'],
                'editable' => $cfg['editable'],
                'sortable' => $cfg['sortable'],
            ];
        }

        return response()->json(['entities' => $entities]);
    }

    public function list(Request $request, string $entity)
    {
        $cfg = EntityRegistry::get($entity);

        $data = $request->validate([
            'q' => 'sometimes|string|max:200',
            'sort' => 'sometimes|string',
            'dir' => 'sometimes|in:asc,desc',
            'per_page' => 'sometimes|integer|min:1|max:200',
        ]);

        [$defaultSort, $defaultDir] = $cfg['default_sort'];
        $sort = in_array($data['sort'] ?? '', $cfg['sortable'], true) ? $data['sort'] : $defaultSort;
        $dir = $data['dir'] ?? $defaultDir;

        $query = $cfg['model']::query()->with($cfg['with']);

        if (($data['q'] ?? '') !== '' && $cfg['searchable'] !== []) {
            $q = mb_strtolower($data['q']);
            $query->where(function ($w) use ($cfg, $q) {
                foreach ($cfg['searchable'] as $col) {
                    // CAST + LOWER keeps the search case-insensitive and working on
                    // non-text columns (e.g. integer ticket_number) on pgsql and sqlite.
                    $w->orWhereRaw("LOWER(CAST({$col} AS TEXT)) LIKE ?", ["%{$q}%"]);
                }
            });
        }

        return response()->json(
            $query->orderBy($sort, $dir)->paginate((int) ($data['per_page'] ?? 25))
        );
    }

    public function show(string $entity, int $id)
    {
        $cfg = EntityRegistry::get($entity);

        $record = $cfg['model']::with($cfg['with'])
            ->withCount($cfg['counts'])
            ->findOrFail($id);

        return response()->json([
            'record' => $record,
            'editable' => $cfg['editable'],
        ]);
    }

    public function update(Request $request, string $entity, int $id)
    {
        $cfg = EntityRegistry::get($entity);

        $unknown = array_diff(array_keys($request->all()), $cfg['editable']);
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Fields not editable for this entity: '.implode(', ', $unknown),
            ], 422);
        }

        $record = $cfg['model']::findOrFail($id);
        $record->forceFill($request->all())->save();

        return response()->json(['record' => $record->fresh()]);
    }

    public function destroy(string $entity, int $id)
    {
        $cfg = EntityRegistry::get($entity);

        $record = $cfg['model']::findOrFail($id);

        // Files also live on disk — remove the blob alongside the row.
        if ($record instanceof CardImage) {
            Storage::disk($record->disk ?: 'public')->delete($record->path);
        }
        if ($record instanceof CardDocument) {
            Storage::disk($record->disk ?: 'local')->delete($record->path);
        }

        $record->delete();

        return response()->noContent();
    }
}
