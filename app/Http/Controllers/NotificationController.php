<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index()
    {
        return Auth::user()->notifications()
            ->take(50)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->data['type'] ?? null,
                'message' => $n->data['message'] ?? '',
                'board_id' => $n->data['board_id'] ?? null,
                'card_id' => $n->data['card_id'] ?? null,
                'deep_link' => $n->data['deep_link'] ?? null,
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ]);
    }

    public function markRead(string $id)
    {
        Auth::user()->notifications()->where('id', $id)->firstOrFail()->markAsRead();

        return response()->json(null, 204);
    }

    public function markAllRead()
    {
        Auth::user()->unreadNotifications->markAsRead();

        return response()->json(null, 204);
    }
}
