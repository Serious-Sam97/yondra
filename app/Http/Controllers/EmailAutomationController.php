<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\EmailStageAutomation;
use App\Infrastructure\Models\EmailStageSend;
use App\Infrastructure\Models\Section;
use Illuminate\Http\Request;

/**
 * Per-board stage→email-template config (card #53). Owner-level: these settings decide
 * what gets auto-sent to clients, so they're gated like sharing/deletion. Mirror of
 * {@see WhatsappAutomationController}.
 */
class EmailAutomationController extends Controller
{
    /** Every section on the board, each with its email automation (or null) + last send. */
    public function index(int $boardId)
    {
        $this->authorizeManage($boardId);

        $automations = EmailStageAutomation::where('board_id', $boardId)
            ->get()
            ->keyBy('section_id');

        // Most recent send per section, for a "last sent" readout in the UI.
        $lastSends = EmailStageSend::whereIn(
            'section_id',
            Section::where('board_id', $boardId)->pluck('id')
        )
            ->orderByDesc('sent_at')
            ->get()
            ->groupBy('section_id')
            ->map(fn ($group) => $group->first());

        return Section::where('board_id', $boardId)
            ->orderBy('order')
            ->get(['id', 'name'])
            ->map(fn ($section) => [
                'section_id' => $section->id,
                'section_name' => $section->name,
                'automation' => $automations->get($section->id),
                'last_send' => $lastSends->get($section->id),
            ]);
    }

    /** Create/update the email automation for one section (and optionally resume a paused one). */
    public function upsert(Request $request, int $boardId, int $sectionId)
    {
        $this->authorizeManage($boardId);

        // The section must belong to this board.
        Section::where('board_id', $boardId)->findOrFail($sectionId);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'enabled' => ['sometimes', 'boolean'],
            'resume' => ['sometimes', 'boolean'],
        ]);

        $automation = EmailStageAutomation::firstOrNew([
            'board_id' => $boardId,
            'section_id' => $sectionId,
        ]);
        $automation->subject = $validated['subject'];
        $automation->body = $validated['body'];
        $automation->enabled = $validated['enabled'] ?? true;
        if (! empty($validated['resume'])) {
            $automation->paused_at = null;
        }
        $automation->save();

        return response()->json($automation, $automation->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(int $boardId, int $sectionId)
    {
        $this->authorizeManage($boardId);

        EmailStageAutomation::where('board_id', $boardId)
            ->where('section_id', $sectionId)
            ->delete();

        return response()->json(null, 204);
    }
}
