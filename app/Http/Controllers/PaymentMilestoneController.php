<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\PaymentMilestone;
use App\Infrastructure\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-board payment milestone rules (YON-63). Owner-level, like the WhatsApp/email
 * stage automations: these decide what auto-fires at a client when a deal is paid.
 */
class PaymentMilestoneController extends Controller
{
    /** All milestones for the board, plus the section list so the UI can pick a move target. */
    public function index(int $boardId)
    {
        $this->authorizeManage($boardId);

        return response()->json([
            'milestones' => PaymentMilestone::where('board_id', $boardId)
                ->with('moveToSection:id,name')
                ->orderBy('threshold_pct')
                ->get(),
            'sections' => Section::where('board_id', $boardId)
                ->orderBy('order')
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, int $boardId)
    {
        $this->authorizeManage($boardId);
        $data = $this->validated($request, $boardId, null);

        $milestone = PaymentMilestone::create(array_merge($data, ['board_id' => $boardId]));

        return response()->json($milestone->load('moveToSection:id,name'), 201);
    }

    public function update(Request $request, int $boardId, int $milestoneId)
    {
        $this->authorizeManage($boardId);
        $milestone = PaymentMilestone::where('board_id', $boardId)->findOrFail($milestoneId);

        $milestone->update($this->validated($request, $boardId, $milestoneId));

        return response()->json($milestone->load('moveToSection:id,name'));
    }

    public function destroy(int $boardId, int $milestoneId)
    {
        $this->authorizeManage($boardId);

        PaymentMilestone::where('board_id', $boardId)->where('id', $milestoneId)->delete();

        return response()->json(null, 204);
    }

    /** Shared validation. `$ignoreId` lets update keep the row's own threshold. */
    private function validated(Request $request, int $boardId, ?int $ignoreId): array
    {
        return $request->validate([
            'threshold_pct' => [
                'required', 'integer', 'min:1', 'max:100',
                Rule::unique('payment_milestones')->where('board_id', $boardId)->ignore($ignoreId),
            ],
            'label' => ['nullable', 'string', 'max:80'],
            'notify' => ['sometimes', 'boolean'],
            'channel' => ['sometimes', 'in:auto,whatsapp,email'],
            'whatsapp_template_name' => ['nullable', 'string', 'max:255'],
            'language' => ['sometimes', 'string', 'max:10'],
            'email_subject' => ['nullable', 'string', 'max:255'],
            'email_body' => ['nullable', 'string', 'max:20000'],
            'move_to_section_id' => [
                'nullable', 'integer',
                Rule::exists('sections', 'id')->where('board_id', $boardId),
            ],
            'generate_invoice' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);
    }
}
