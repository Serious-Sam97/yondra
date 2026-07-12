<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\BoardActivity;

class BoardActivityController extends Controller
{
    public function index(int $boardId)
    {
        $this->authorizeBoard($boardId);

        return BoardActivity::where('board_id', $boardId)
            ->with('user:id,name')
            ->latest()
            ->limit(50)
            ->get();
    }
}
