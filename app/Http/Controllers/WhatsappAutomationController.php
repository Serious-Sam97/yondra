<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\WhatsappStageAutomation;
use Illuminate\Http\Request;

/**
 * Per-board stage→template automation config (card #58). Owner-level: these settings
 * decide what gets auto-sent to customers, so they're gated like sharing/deletion.
 */
class WhatsappAutomationController extends Controller
{
    /** Every section on the board, each with its automation config (or null). */
    public function index(int $boardId)
    {
        $this->authorizeManage($boardId);

        $automations = WhatsappStageAutomation::where('board_id', $boardId)
            ->get()
            ->keyBy('section_id');

        return Section::where('board_id', $boardId)
            ->orderBy('order')
            ->get(['id', 'name'])
            ->map(fn ($section) => [
                'section_id' => $section->id,
                'section_name' => $section->name,
                'automation' => $automations->get($section->id),
            ]);
    }

    /** Create/update the automation for one section (and optionally resume a paused one). */
    public function upsert(Request $request, int $boardId, int $sectionId)
    {
        $this->authorizeManage($boardId);

        // The section must belong to this board.
        Section::where('board_id', $boardId)->findOrFail($sectionId);

        $validated = $request->validate([
            'template_name' => ['required', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:10'],
            'enabled' => ['sometimes', 'boolean'],
            'resume' => ['sometimes', 'boolean'],
        ]);

        $automation = WhatsappStageAutomation::firstOrNew([
            'board_id' => $boardId,
            'section_id' => $sectionId,
        ]);
        $automation->template_name = $validated['template_name'];
        $automation->language = $validated['language'] ?? 'en';
        $automation->enabled = $validated['enabled'] ?? true;
        // Clearing the quality pause is an explicit, human-in-the-loop action.
        if (! empty($validated['resume'])) {
            $automation->paused_at = null;
        }
        $automation->save();

        return response()->json($automation, $automation->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(int $boardId, int $sectionId)
    {
        $this->authorizeManage($boardId);

        WhatsappStageAutomation::where('board_id', $boardId)
            ->where('section_id', $sectionId)
            ->delete();

        return response()->json(null, 204);
    }
}
