<?php

namespace App\Support\MediaRequests;

/**
 * HC Generator, the centre's do-it-yourself site. A lot of what people ask
 * the media team for (a poster from a project template, a quick reel, a
 * YouTube thumbnail, proofreading) they can make there in minutes. The
 * request form uses this to point them to the right tool before they send
 * a request; they can still send it if the tool is not enough.
 *
 * Reads what they wrote and, once they pick a type, what they picked. Rules
 * live in config('media-requests.generator').
 */
class HcGenerator
{
    public function url(string $path = '/'): string
    {
        return rtrim((string) config('media-requests.generator.url'), '/').$path;
    }

    /**
     * The first tool that fits, or null.
     *
     * @return array{key: string, url: string, icon: string}|null
     */
    public function suggest(string $text, ?string $service = null, ?string $type = null, array $answers = []): ?array
    {
        $text = mb_substr(trim($text), 0, 1000);

        foreach (config('media-requests.generator.tools', []) as $key => $tool) {
            if ($text !== '' && isset($tool['unless']) && $this->matches($tool['unless'], $text)) {
                continue;
            }

            $byText = $text !== '' && $this->matches($tool['match'], $text);
            $byType = collect($tool['types'] ?? [])->contains(fn (array $t): bool => $this->picked($t, $service, $type, $answers));

            if ($byText || $byType) {
                return ['key' => $key, 'url' => $this->url($tool['path']), 'icon' => $tool['icon']];
            }
        }

        return null;
    }

    /** [service, type, field, value, (field, value)] matches the picked type and answers. */
    private function picked(array $rule, ?string $service, ?string $type, array $answers): bool
    {
        [$s, $t] = $rule;
        $pairs = array_chunk(array_slice($rule, 2), 2);

        if ($s !== $service || $t !== $type) {
            return false;
        }

        foreach ($pairs as [$field, $value]) {
            if (! in_array($value, (array) ($answers[$field] ?? []), true)) {
                return false;
            }
        }

        return true;
    }

    private function matches(string $pattern, string $text): bool
    {
        return preg_match('/'.$pattern.'/iu', $text) === 1;
    }
}
