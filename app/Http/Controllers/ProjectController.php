<?php

namespace App\Http\Controllers;

use App\Services\ProjectService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    private ProjectService $service;

    public function __construct()
    {
        $this->service = resolve(ProjectService::class);
    }

    public function index()
    {
        return $this->service->fetchAll();
    }

    public function show(int $projectId)
    {
        return $this->service->fetchOne($projectId);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        return response()->json($this->service->create($validated), 201);
    }

    public function update(Request $request, int $projectId)
    {
        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $validated['id'] = $projectId;

        return $this->service->update($validated);
    }

    public function destroy(int $projectId)
    {
        $this->service->remove($projectId);
        return response()->json(null, 204);
    }
}
