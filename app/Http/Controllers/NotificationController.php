<?php
namespace App\Http\Controllers;

use App\Infrastructure\Models\YondraNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index()
    {
        return YondraNotification::where('user_id', Auth::id())
            ->latest()
            ->limit(30)
            ->get();
    }

    public function markRead(int $id)
    {
        YondraNotification::where('user_id', Auth::id())->findOrFail($id)
            ->update(['read_at' => now()]);
        return response()->json(null, 204);
    }

    public function markAllRead()
    {
        YondraNotification::where('user_id', Auth::id())->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(null, 204);
    }
}
