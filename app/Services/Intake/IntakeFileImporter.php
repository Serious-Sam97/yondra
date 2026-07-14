<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads files a client attached to an intake form and stores them as card
 * documents on the private disk — the same shape as a manual upload. Best-effort:
 * a file that can't be fetched or isn't an allowed type is skipped with a log
 * line, never aborting the (already-created) card.
 */
class IntakeFileImporter
{
    /** Extensions accepted as documents — mirrors CardDocumentController::ALLOWED. */
    private const ALLOWED = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'md', 'rtf', 'odt', 'ods', 'odp', 'zip'];

    private const MAX_BYTES = 20 * 1024 * 1024; // 20MB, matching the manual-upload cap.

    /**
     * @param  array<int,string>  $urls
     * @return int number of files successfully imported
     */
    public function import(Card $card, array $urls): int
    {
        $imported = 0;
        foreach ($urls as $url) {
            if ($this->importOne($card, $url)) {
                $imported++;
            }
        }

        return $imported;
    }

    private function importOne(Card $card, string $url): bool
    {
        $name = $this->filenameFromUrl($url);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED, true)) {
            Log::info('Intake: skipping attachment with disallowed type', ['url' => $url, 'ext' => $ext]);

            return false;
        }

        try {
            $response = Http::withOptions(['stream' => false])
                ->timeout(30)
                ->get($this->authorizeUrl($url));
        } catch (\Throwable $e) {
            Log::warning('Intake: attachment download failed', ['url' => $url, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Intake: attachment download returned error status', ['url' => $url, 'status' => $response->status()]);

            return false;
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            Log::warning('Intake: attachment empty or over size cap', ['url' => $url, 'bytes' => strlen($body)]);

            return false;
        }

        $path = "card-documents/{$card->id}/".Str::random(40).'.'.$ext;
        Storage::disk('local')->put($path, $body);

        $position = CardDocument::where('card_id', $card->id)->max('position') + 1;
        CardDocument::create([
            'card_id' => $card->id,
            'user_id' => null, // system import — no uploader user
            'disk' => 'local',
            'path' => $path,
            'original_name' => $name,
            'mime_type' => $response->header('Content-Type') ?: 'application/octet-stream',
            'size' => strlen($body),
            'position' => $position,
        ]);

        return true;
    }

    /** JotForm upload URLs are gated behind ?apiKey=…; append it when we have one. */
    private function authorizeUrl(string $url): string
    {
        $key = config('services.jotform.api_key');
        if ($key && str_contains(strtolower($url), 'jotform.com')) {
            return $url.(str_contains($url, '?') ? '&' : '?').'apiKey='.urlencode($key);
        }

        return $url;
    }

    private function filenameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path);

        return $base !== '' ? rawurldecode($base) : 'attachment';
    }
}
