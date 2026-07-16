<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\ImportModel;
use App\Infrastructure\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CRUD for project-scoped custom JSON import models (YON-122). Reading is open to
 * any project member (the board's import tool lists them); creating/editing is
 * gated on project ownership, mirroring how other manager-only project config
 * behaves. The applied mapping lives in App\Services\CardImport\ImportModelMapper.
 */
class ImportModelController extends Controller
{
    private const TARGETS = [
        'name', 'description', 'priority', 'due_date', 'story_points', 'value',
        'tags', 'column', 'contact_name', 'contact_email', 'contact_phone',
    ];

    public function index(int $projectId)
    {
        $project = $this->accessibleProject($projectId);

        return ImportModel::where('project_id', $project->id)->orderBy('name')->get();
    }

    public function store(Request $request, int $projectId)
    {
        $project = $this->managedProject($projectId);
        $data = $this->validated($request, partial: false);

        $model = ImportModel::create([
            'project_id' => $project->id,
            'name' => $data['name'],
            'mode' => $data['mode'] ?? 'many',
            'item_path' => $data['item_path'] ?? null,
            'fields' => $data['fields'],
            'sample' => $data['sample'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return response()->json($model, 201);
    }

    public function update(Request $request, int $projectId, int $modelId)
    {
        $project = $this->managedProject($projectId);
        $model = ImportModel::where('project_id', $project->id)->findOrFail($modelId);

        $data = $this->validated($request, partial: true);
        // Only overwrite the keys actually sent so a partial PATCH-style update
        // (e.g. just renaming) leaves the mapping untouched.
        $model->fill($data)->save();

        return $model->fresh();
    }

    public function destroy(int $projectId, int $modelId)
    {
        $project = $this->managedProject($projectId);
        $model = ImportModel::where('project_id', $project->id)->findOrFail($modelId);
        $model->delete();

        return response()->json(null, 204);
    }

    /**
     * Validate + return the writable subset. On update, every field is optional
     * (`sometimes`) so callers can send just what changed.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'mode' => ['sometimes', 'in:many,one'],
            'item_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fields' => [$req, 'array'],
            'fields.*.target' => ['required', 'string', 'in:'.implode(',', self::TARGETS)],
            'fields.*.source' => ['nullable', 'string', 'max:255'],
            'fields.*.transform' => ['nullable', 'array'],
            'fields.*.transform.type' => ['nullable', 'in:none,const,split,scale,date,number'],
            'sample' => ['sometimes', 'nullable', 'array'],
        ]);

        // validated() drops nested keys that have no rule of their own — that would
        // strip each transform's params (const.value, split.delimiter, scale.map).
        // The field shape is validated above, so store the raw field objects whole.
        if (array_key_exists('fields', $data)) {
            $data['fields'] = $request->input('fields');
        }

        return $data;
    }

    private function accessibleProject(int $projectId): Project
    {
        $project = Project::findOrFail($projectId);
        if (! $project->isAccessibleBy(Auth::id())) {
            throw new AccessDeniedHttpException;
        }

        return $project;
    }

    private function managedProject(int $projectId): Project
    {
        $project = Project::findOrFail($projectId);
        if (! $project->isOwnedBy(Auth::id())) {
            throw new AccessDeniedHttpException;
        }

        return $project;
    }
}
