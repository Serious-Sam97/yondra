<?php

namespace App\Http\Controllers\Vortex;

use App\Http\Controllers\Controller;
use App\Infrastructure\Models\CardImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SystemController extends Controller
{
    public function index()
    {
        $driver = DB::connection()->getDriverName();

        $dbSize = null;
        if ($driver === 'pgsql') {
            $dbSize = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS s')->s;
        }

        return response()->json([
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'env' => config('app.env'),
            'debug' => (bool) config('app.debug'),
            'drivers' => [
                'db' => $driver,
                'queue' => config('queue.default'),
                'cache' => config('cache.default'),
                'session' => config('session.driver'),
                'broadcast' => config('broadcasting.default'),
                'filesystem' => config('filesystems.default'),
            ],
            'db_size_bytes' => $dbSize,
            'logs_bytes' => array_sum(array_map('filesize', glob(storage_path('logs/*.log')) ?: [])),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function queue()
    {
        $byQueue = DB::table('jobs')
            ->selectRaw('queue, count(*) as pending, min(available_at) as oldest')
            ->groupBy('queue')
            ->get()
            ->map(fn ($r) => [
                'queue' => $r->queue,
                'pending' => (int) $r->pending,
                'oldest_age_seconds' => $r->oldest ? max(0, now()->timestamp - (int) $r->oldest) : null,
            ]);

        return response()->json([
            'queues' => $byQueue,
            'pending_total' => DB::table('jobs')->count(),
            'failed_total' => DB::table('failed_jobs')->count(),
        ]);
    }

    public function failedJobs()
    {
        $page = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->paginate(25, ['id', 'uuid', 'connection', 'queue', 'exception', 'failed_at']);

        $page->getCollection()->transform(function ($job) {
            // Only the first line of the exception is useful in a list view.
            $job->exception = strtok($job->exception, "\n");

            return $job;
        });

        return response()->json($page);
    }

    public function retryFailedJob(string $uuid)
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['output' => trim(Artisan::output())]);
    }

    public function forgetFailedJob(string $uuid)
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return response()->json(['output' => trim(Artisan::output())]);
    }

    public function flushFailedJobs()
    {
        Artisan::call('queue:flush');

        return response()->json(['output' => trim(Artisan::output())]);
    }

    public function clearCache(Request $request)
    {
        $data = $request->validate([
            'targets' => 'required|array|min:1',
            'targets.*' => 'in:cache,config,route,view',
        ]);

        $results = [];
        foreach ($data['targets'] as $target) {
            Artisan::call("{$target}:clear");
            $results[$target] = trim(Artisan::output());
        }

        return response()->json(['results' => $results]);
    }

    public function logFiles()
    {
        $files = array_map(fn ($path) => [
            'name' => basename($path),
            'size_bytes' => filesize($path),
            'modified_at' => date('c', filemtime($path)),
        ], glob(storage_path('logs/*.log')) ?: []);

        return response()->json(['files' => $files]);
    }

    public function logs(Request $request)
    {
        $data = $request->validate([
            'file' => 'sometimes|string',
            'lines' => 'sometimes|integer|min:10|max:2000',
        ]);

        // basename() strips any path traversal; the file must then exist in logs/.
        $name = basename($data['file'] ?? 'laravel.log');
        $path = storage_path("logs/{$name}");

        abort_unless(is_file($path), 404, "No such log file: {$name}");

        return response()->json([
            'file' => $name,
            'size_bytes' => filesize($path),
            'lines' => $this->tail($path, (int) ($data['lines'] ?? 200)),
        ]);
    }

    public function storage()
    {
        $disk = Storage::disk('public');

        $dirs = [];
        foreach ($disk->directories() as $dir) {
            $files = $disk->allFiles($dir);
            $dirs[] = [
                'dir' => $dir,
                'files' => count($files),
                'size_bytes' => array_sum(array_map(fn ($f) => $disk->size($f), $files)),
            ];
        }

        // Attachments on disk that no card_images row references anymore.
        $onDisk = collect($disk->allFiles('cards'));
        $inDb = CardImage::pluck('path');
        $orphans = $onDisk->diff($inDb);

        return response()->json([
            'dirs' => $dirs,
            'orphans' => [
                'count' => $orphans->count(),
                'size_bytes' => $orphans->sum(fn ($f) => $disk->size($f)),
                'sample' => $orphans->take(10)->values(),
            ],
        ]);
    }

    /** Read the last N lines of a file without loading it whole. @return string[] */
    private function tail(string $path, int $lines): array
    {
        $handle = fopen($path, 'rb');
        $buffer = '';
        $chunk = 8192;
        $pos = filesize($path);

        while ($pos > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $buffer = fread($handle, $read).$buffer;
        }
        fclose($handle);

        $all = explode("\n", rtrim($buffer, "\n"));

        return array_slice($all, -$lines);
    }
}
