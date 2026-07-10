<?php

namespace App\Http\Controllers\Vortex;

use App\Http\Controllers\Controller;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardActivity;
use App\Infrastructure\Models\BoardMessage;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\CardDocument;
use App\Infrastructure\Models\CardImage;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\Sprint;
use App\Infrastructure\Models\Tag;
use App\Infrastructure\Models\TestCase;
use App\Infrastructure\Models\User;
use App\Infrastructure\Models\YondraNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OverviewController extends Controller
{
    public function index()
    {
        return response()->json([
            'counts' => [
                'users' => User::count(),
                'projects' => Project::count(),
                'boards' => Board::count(),
                'sections' => Section::count(),
                'cards' => Card::count(),
                'cards_archived' => Card::whereNotNull('archived_at')->count(),
                'card_images' => CardImage::count(),
                'card_documents' => CardDocument::count(),
                'comments' => CardComment::count(),
                'messages' => BoardMessage::count(),
                'activities' => BoardActivity::count(),
                'tags' => Tag::count(),
                'sprints' => Sprint::count(),
                'board_shares' => BoardShare::count(),
                'test_cases' => TestCase::count(),
                'notifications' => YondraNotification::count(),
                'tokens' => DB::table('personal_access_tokens')->count(),
            ],
            'storage' => [
                'attachments_bytes' => (int) CardImage::sum('size'),
                'documents_bytes' => (int) CardDocument::sum('size'),
                'disk_public_bytes' => Cache::remember('vortex.disk_public_bytes', 60, function () {
                    $disk = Storage::disk('public');

                    return array_sum(array_map(fn ($f) => $disk->size($f), $disk->allFiles()));
                }),
            ],
            'recent_users' => User::latest()->take(5)->get(['id', 'name', 'email', 'created_at']),
            'recent_activity' => BoardActivity::with(['user:id,name', 'board:id,name'])
                ->latest()
                ->take(20)
                ->get(),
            'system' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'db_driver' => DB::connection()->getDriverName(),
                'queue' => config('queue.default'),
                'env' => config('app.env'),
            ],
        ]);
    }

    public function timeseries(Request $request)
    {
        $data = $request->validate([
            'days' => 'sometimes|integer|min:1|max:365',
            'metrics' => 'sometimes|string',
        ]);

        $days = (int) ($data['days'] ?? 30);

        $available = [
            'users' => User::class,
            'cards' => Card::class,
            'activities' => BoardActivity::class,
            'comments' => CardComment::class,
            'card_images' => CardImage::class,
        ];

        $requested = array_filter(
            array_map('trim', explode(',', $data['metrics'] ?? 'cards')),
            fn ($m) => array_key_exists($m, $available)
        );
        if ($requested === []) {
            $requested = ['cards'];
        }

        $from = now()->subDays($days - 1)->startOfDay();

        // Zero-filled calendar so the chart never has gaps.
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = $from->copy()->addDays($i)->toDateString();
        }

        $series = [];
        foreach ($requested as $metric) {
            $rows = $available[$metric]::query()
                ->where('created_at', '>=', $from)
                ->selectRaw('DATE(created_at) as d, count(*) as c')
                ->groupBy('d')
                ->pluck('c', 'd');

            $series[$metric] = array_map(
                fn ($date) => ['date' => $date, 'count' => (int) ($rows[$date] ?? 0)],
                $dates
            );
        }

        return response()->json(['days' => $days, 'series' => $series]);
    }
}
