<?php

declare(strict_types=1);

namespace App\Services\Vortex;

use App\Infrastructure\Models\VortexMemory;
use App\Services\Ai\AiDriver;
use Illuminate\Support\Facades\Log;

/**
 * F-02 · THE DOSSIER. After a chat turn, a small extraction pass pulls 0–3
 * durable facts about the user ("works in marketing", "has a cat called
 * Pixel", "dreads friday's launch"). Sensitive categories are never kept.
 * Relevant facts are fed back into his prompt; the user can read and delete
 * every one in the drawer.
 */
final class MemoryService
{
    public const CATEGORIES = ['work', 'habit', 'like', 'dislike', 'life', 'project', 'fear', 'goal'];

    /** Never stored, whatever the model says (also enforced in the prompt). */
    private const FORBIDDEN = '/\b(health|sick|illness|diagnos|medic|therap|depress|anxiety|suicid|pregnan|religio|church|god|pray|politic|vote|elect|sexual|gay|lesbian|trans|salary|debt|loan|bank|password|address|cpf|ssn|doen[çc]a|rem[ée]dio|religi|igreja|pol[íi]tic|sal[áa]rio|d[íi]vida|senha|endere[çc]o)/iu';

    private const MAX_PER_USER = 120;

    public function __construct(private readonly AiDriver $ai) {}

    /** Extract and store facts from one exchange. Returns what was saved. */
    public function extract(int $userId, string $userText, string $reply): array
    {
        if (mb_strlen(trim($userText)) < 12 || ! $this->ai->isAvailable()) {
            return [];
        }
        $system = 'Extract durable personal facts about the USER from this chat exchange, for a mascot that wants to remember them. '
            .'Only facts the user stated or clearly implied about THEMSELVES (job, habits, likes, dislikes, pets, projects, fears about work, goals — and HOW they like to work: card size, which columns they use, labels or not, how they write descriptions; use category "work" for those). '
            .'Never extract anything about health, mental health, religion, politics, sexuality, money/debt, passwords, addresses or other people\'s private lives. '
            .'Respond with JSON only: {"facts":[{"category":"work|habit|like|dislike|life|project|fear|goal","fact":"short third-person fact, max 120 chars"}]} — '
            .'an empty list is the usual answer.';
        try {
            AiBudget::spend($userId, 'memory');
            $raw = $this->ai->complete($system, [['role' => 'user', 'content' => "USER: {$userText}\nVORTEX: {$reply}"]], 300, true);
        } catch (\Throwable $e) {
            Log::info('Vortex memory extraction skipped', ['error' => $e->getMessage()]);

            return [];
        }
        $json = json_decode($this->firstObject($raw), true);
        $saved = [];
        $existing = VortexMemory::where('user_id', $userId)->pluck('fact')->map(fn ($f) => mb_strtolower($f))->all();
        foreach (array_slice((array) ($json['facts'] ?? []), 0, 3) as $f) {
            $cat = (string) ($f['category'] ?? '');
            $fact = trim((string) ($f['fact'] ?? ''));
            if (! in_array($cat, self::CATEGORIES, true) || $fact === '' || mb_strlen($fact) > 160) {
                continue;
            }
            if (preg_match(self::FORBIDDEN, $fact) === 1 || in_array(mb_strtolower($fact), $existing, true)) {
                continue;
            }
            $saved[] = VortexMemory::create(['user_id' => $userId, 'category' => $cat, 'fact' => $fact, 'source' => 'chat']);
        }
        $this->prune($userId);

        return $saved;
    }

    /**
     * Up to $limit facts relevant to this message: keyword overlap first, then
     * the most recent. Plain text lines for the prompt.
     *
     * @return list<string>
     */
    public function recall(int $userId, string $text, int $limit = 8): array
    {
        $all = VortexMemory::where('user_id', $userId)->orderByDesc('id')->limit(self::MAX_PER_USER)->get(['fact']);
        if ($all->isEmpty()) {
            return [];
        }
        $words = array_filter(preg_split('/\W+/u', mb_strtolower($text)) ?: [], fn ($w) => mb_strlen($w) > 3);
        $scored = $all->map(function ($m) use ($words) {
            $f = mb_strtolower($m->fact);
            $score = 0;
            foreach ($words as $w) {
                if (str_contains($f, $w)) {
                    $score++;
                }
            }

            return ['fact' => $m->fact, 'score' => $score];
        });
        $top = $scored->sortByDesc('score')->take(4)->filter(fn ($s) => $s['score'] > 0)->pluck('fact');
        $recent = $all->pluck('fact')->take($limit);

        return $top->merge($recent)->unique()->take($limit)->values()->all();
    }

    private function prune(int $userId): void
    {
        $ids = VortexMemory::where('user_id', $userId)->orderByDesc('id')->skip(self::MAX_PER_USER)->take(50)->pluck('id');
        if ($ids->isNotEmpty()) {
            VortexMemory::whereIn('id', $ids)->delete();
        }
    }

    private function firstObject(string $raw): string
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        return $start !== false && $end !== false ? substr($raw, $start, $end - $start + 1) : '{}';
    }
}
