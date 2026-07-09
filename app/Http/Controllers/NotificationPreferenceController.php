<?php

namespace App\Http\Controllers;

use App\Services\NotificationPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationPreferenceController extends Controller
{
    public function __construct(private NotificationPreferenceService $prefs) {}

    /** The full matrix + catalog the settings UI renders from. */
    public function show()
    {
        return $this->prefs->catalog(Auth::user());
    }

    /** Persist a (possibly partial) matrix; unknown events/channels are dropped. */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
        ]);

        $user = Auth::user();
        $user->notification_preferences = $this->prefs->sanitize($validated['preferences']);
        $user->save();

        return $this->prefs->catalog($user);
    }
}
