<?php

namespace App\Support\MediaRequests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The proofreader on the home screen and in the request form.
 *
 * Two layers:
 *   1. Punctuation: spacing, commas, numerals. Local, instant, never changes a word.
 *   2. Gemini, with the team's proofreading instructions (resources/prompts/proofread.md):
 *      spelling, grammar, a consistent tone; terms and the organisation's style kept.
 *
 * Without a key (GEMINI_API_KEY), or when Gemini cannot be reached, only the
 * first layer runs and the result says so. The text is sent to Google only to
 * be checked; nothing is stored here.
 */
class Proofreader
{
    public const MAX_CHARS = 20000;   // about 8 pages of a Word file

    public function __construct(private Punctuation $punctuation) {}

    public function aiAvailable(): bool
    {
        return (string) config('media-requests.proofread.key') !== '';
    }

    /** Checks left today for this person. */
    public function remaining(int $userId): int
    {
        return RateLimiter::remaining($this->limitKey($userId), (int) config('media-requests.proofread.daily_limit', 40));
    }

    /**
     * @return array{original: string, corrected: string, changes: array<int, array{before: string, after: string, note: string}>,
     *               suggestions: array<int, string>, summary: string, language: string, ai: bool, aiError: ?string, localFixes: array<int, string>}
     */
    public function check(string $text, int $userId): array
    {
        $text = mb_substr(trim($text), 0, self::MAX_CHARS);
        $local = $this->punctuation->fix($text);

        $result = [
            'original' => $text,
            'corrected' => $local['text'],
            'changes' => [],
            'suggestions' => [],
            'summary' => '',
            'language' => preg_match_all('/\p{Arabic}/u', $text) >= preg_match_all('/[A-Za-z]/', $text) ? 'ar' : 'en',
            'ai' => false,
            'aiError' => null,
            'localFixes' => $local['fixes'],
        ];

        if ($text === '' || ! $this->aiAvailable()) {
            $result['aiError'] = $text === '' ? null : 'off';

            return $result;
        }

        $limit = (int) config('media-requests.proofread.daily_limit', 40);
        if (RateLimiter::tooManyAttempts($this->limitKey($userId), $limit)) {
            $result['aiError'] = 'limit';

            return $result;
        }
        RateLimiter::hit($this->limitKey($userId), 86400);

        try {
            $ai = $this->askGemini($local['text']);
        } catch (\Throwable $e) {
            Log::warning('Proofreader: Gemini failed', ['error' => $e->getMessage()]);
            $result['aiError'] = 'down';

            return $result;
        }

        // The AI's text goes through the punctuation pass too, so spacing stays right.
        $result['corrected'] = $this->punctuation->fix((string) ($ai['corrected'] ?? $local['text']))['text'];
        $result['changes'] = array_values(array_filter(array_map(fn ($c) => is_array($c) ? [
            'before' => (string) ($c['before'] ?? ''), 'after' => (string) ($c['after'] ?? ''), 'note' => (string) ($c['note'] ?? ''),
        ] : null, (array) ($ai['changes'] ?? []))));
        $result['suggestions'] = array_values(array_filter(array_map('strval', (array) ($ai['suggestions'] ?? []))));
        $result['summary'] = (string) ($ai['summary'] ?? '');
        $result['language'] = in_array($ai['language'] ?? null, ['ar', 'en'], true) ? $ai['language'] : $result['language'];
        $result['ai'] = true;

        return $result;
    }

    /**
     * Words of $before and $after side by side: [text, 'same' | 'del' | 'add'], for showing what changed.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function diff(string $before, string $after): array
    {
        $a = preg_split('/(\s+)/u', $before, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('/(\s+)/u', $after, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($a) * count($b) > 400000) {   // too long to compare word by word: show the result only
            return [[$after, 'same']];
        }

        // Longest common subsequence, then walk it.
        $n = count($a);
        $m = count($b);
        $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $l[$i][$j] = $a[$i] === $b[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
            }
        }

        $out = [];
        $push = function (string $t, string $k) use (&$out): void {
            if ($out && end($out)[1] === $k) {
                $out[count($out) - 1][0] .= $t;
            } else {
                $out[] = [$t, $k];
            }
        };
        [$i, $j] = [0, 0];
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $push($a[$i++], 'same');
                $j++;
            } elseif ($l[$i + 1][$j] >= $l[$i][$j + 1]) {
                $push($a[$i++], 'del');
            } else {
                $push($b[$j++], 'add');
            }
        }
        while ($i < $n) {
            $push($a[$i++], 'del');
        }
        while ($j < $m) {
            $push($b[$j++], 'add');
        }

        return $out;
    }

    /** Ask each model in turn until one answers properly. */
    private function askGemini(string $text): array
    {
        $prompt = (string) file_get_contents(resource_path('prompts/proofread.md'));
        $models = (array) config('media-requests.proofread.models', ['gemini-flash-lite-latest', 'gemini-flash-latest']);
        $last = null;

        foreach ($models as $model) {
            try {
                return $this->askModel(trim($model), $prompt, $text);
            } catch (\Throwable $e) {
                $last = $e;   // busy, retired or slow: try the next one
            }
        }

        throw $last ?? new \RuntimeException('No Gemini model configured');
    }

    private function askModel(string $model, string $prompt, string $text): array
    {
        $response = Http::timeout(25)
            ->withHeaders(['x-goog-api-key' => (string) config('media-requests.proofread.key')])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $prompt]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
                'generationConfig' => ['temperature' => 0.2, 'responseMimeType' => 'application/json'],
            ])
            ->throw()
            ->json();

        $raw = (string) data_get($response, 'candidates.0.content.parts.0.text', '');
        $json = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($raw)), true);

        if (! is_array($json) || ! isset($json['corrected'])) {
            throw new \RuntimeException('Unexpected answer from Gemini');
        }

        return $json;
    }
    private function limitKey(int $userId): string
    {
        return 'proofread:'.$userId.':'.now()->toDateString();
    }
}
