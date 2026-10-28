<?php

namespace App\Support\MediaRequests;

/**
 * Spacing and punctuation fixes that never change a word, in Arabic and English.
 * Runs locally and instantly, before (or without) the AI proofreader.
 *
 *   "فارس : ب"        -> "فارس: ب"
 *   "مرحبا ,كيف"      -> "مرحبا، كيف"     (Arabic text gets the Arabic comma)
 *   "hello ,how"      -> "hello, how"
 *   "٢٠٢٦"            -> "2026"           (no Indian numerals)
 */
class Punctuation
{
    /** @return array{text: string, fixes: array<int, string>} */
    public function fix(string $text): array
    {
        $fixes = [];
        $apply = function (string $pattern, string|callable $to, string $what) use (&$text, &$fixes): void {
            $new = is_callable($to) ? preg_replace_callback($pattern, $to, $text) : preg_replace($pattern, $to, $text);
            if ($new !== null && $new !== $text) {
                $text = $new;
                $fixes[] = $what;
            }
        };

        $text = str_replace("\r\n", "\n", $text);

        // Indian (Eastern Arabic) and Persian numerals to Western ones.
        $apply('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', fn ($m) => (string) (mb_ord($m[0]) - (mb_ord($m[0]) >= 0x06F0 ? 0x06F0 : 0x0660)), 'numerals');

        // Commas between Arabic words become the Arabic comma.
        $apply('/(\p{Arabic})\s*,\s*(?=\p{Arabic})/u', '$1، ', 'arabicComma');

        // No space before punctuation.
        $apply('/[ \t]+([.,،؛;:!?؟)\]»])/u', '$1', 'spaceBefore');

        // One space after punctuation when a word follows (not inside numbers, times, links or "...").
        $apply('/([,،؛;!?؟])(?=[\p{L}])/u', '$1 ', 'spaceAfter');
        $apply('/(?<![\d.])([:.])(?=\p{Arabic})/u', '$1 ', 'spaceAfter');
        $apply('/(?<=\p{Ll})\.(?=\p{Lu}\p{Ll})/u', '. ', 'spaceAfter');

        // No space just inside brackets and quotes.
        $apply('/([(\[«])[ \t]+/u', '$1', 'brackets');

        // Repeated spaces, and spaces at line ends.
        $apply('/[ \t]{2,}/u', ' ', 'doubleSpace');
        $apply('/[ \t]+$/mu', '', 'trailing');

        // Repeated punctuation (but keep "...").
        $apply('/([،,؛;!?؟])\1+/u', '$1', 'repeated');

        return ['text' => trim($text), 'fixes' => array_values(array_unique($fixes))];
    }
}
