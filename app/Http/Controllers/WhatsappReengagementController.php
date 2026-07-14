<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\WhatsappReengagementPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-board idle-lead re-engagement policy (card YON-62). Owner-level: these settings
 * decide what gets auto-sent to customers and when a lead is retired, so they're gated
 * like sharing/deletion — mirror of WhatsappAutomationController.
 */
class WhatsappReengagementController extends Controller
{
    /** The board's policy (or null) plus its sections, for the Lost-stage picker. */
    public function show(int $boardId)
    {
        $this->authorizeManage($boardId);

        return response()->json([
            'policy' => WhatsappReengagementPolicy::where('board_id', $boardId)->first(),
            'sections' => Section::where('board_id', $boardId)
                ->orderBy('order')
                ->get(['id', 'name']),
        ]);
    }

    /** Create/update the board's re-engagement policy. */
    public function upsert(Request $request, int $boardId)
    {
        $this->authorizeManage($boardId);

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'idle_days' => ['sometimes', 'integer', 'min:1'],
            'retry_interval_days' => ['sometimes', 'integer', 'min:1'],
            'max_attempts' => ['sometimes', 'integer', 'min:1'],
            'template_name' => ['required', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:10'],
            'lost_section_id' => [
                'nullable', 'integer',
                Rule::exists('sections', 'id')->where('board_id', $boardId),
            ],
        ]);

        $policy = WhatsappReengagementPolicy::firstOrNew(['board_id' => $boardId]);
        $policy->fill($validated);
        $policy->language = $validated['language'] ?? $policy->language ?? 'en';
        $policy->save();

        return response()->json($policy, $policy->wasRecentlyCreated ? 201 : 200);
    }
}
