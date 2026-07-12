<?php

namespace App\Console\Commands;

use App\Infrastructure\Models\CardImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-shot backfill for the card-image privatization: moves every card
 * attachment still on the world-readable `public` disk into the private
 * `local` disk (same relative path) and flips the row's `disk`, after which
 * its `url` accessor starts handing out signed streaming URLs.
 *
 * Deliberately a command, not a migration — moving blobs is destructive and
 * should be run (and re-run, it is idempotent) by an operator.
 *
 * Inline rich-text images under boards/* are intentionally NOT moved: their
 * /storage/... URLs are baked into stored HTML descriptions and comments, so
 * relocating the files would break existing content. Those files are already
 * public; only new inline uploads are stored privately.
 */
class PrivatizeImages extends Command
{
    protected $signature = 'yondra:privatize-images';

    protected $description = 'Move card attachment images from the public disk to private storage (served via signed URLs)';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $local = Storage::disk('local');

        $moved = 0;
        $missing = 0;

        CardImage::where('disk', 'public')->orderBy('id')->chunkById(100, function ($images) use ($public, $local, &$moved, &$missing) {
            foreach ($images as $image) {
                if (! $public->exists($image->path)) {
                    // Row points at a blob that no longer exists — leave it for the
                    // Vortex orphan tooling, nothing to privatize.
                    $missing++;

                    continue;
                }

                $stream = $public->readStream($image->path);
                $local->writeStream($image->path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $public->delete($image->path);
                $image->update(['disk' => 'local']);
                $moved++;
            }
        });

        $remaining = CardImage::where('disk', 'public')->count();

        $this->info("Moved {$moved} card image(s) to private storage.");
        if ($missing > 0) {
            $this->warn("{$missing} card image row(s) had no file on the public disk and were left untouched.");
        }
        $this->info("{$remaining} card image(s) still on the public disk.");
        $this->line('Inline rich-text images (boards/*) were not moved: their URLs live inside stored HTML.');

        return self::SUCCESS;
    }
}
