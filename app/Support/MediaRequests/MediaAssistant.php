<?php

namespace App\Support\MediaRequests;

use App\Models\City;
use App\Models\Program;
use Illuminate\Support\Collection;

/**
 * The request assistant. The requester writes what they need in their own
 * words ("لازمني شهادات لطلاب الديسك بحلب الأسبوع الجاي"); this reads it, picks
 * the service and type, pre-fills what it can, and asks only the follow-up
 * questions that are still missing.
 *
 * Runs locally with keyword rules — free, no outside service, works offline.
 * It never asks about the program, governorate or date when the text already
 * names them.
 */
class MediaAssistant
{
    /** [pattern, service, type, answers] — first match wins, most specific first. */
    private const TYPES = [
        ['شهاد|certificat', '001', 'new', ['format' => 'cert']],
        ['بطاق[ةه]?\s*(تعريف|هوي)|\bid\s*card|\bid\b|badge|بادج', '001', 'new', ['format' => 'id']],
        ['دعو[ةه]|دعوات|invitation|invite', '001', 'new', ['format' => 'invite']],
        // Greeting and other cards ("كروت معايدة لطلاب المدارس"): a new design.
        ['معايد|تهنئ|greeting|christmas\s*cards?|كروت|كرت\b|بطاقات', '001', 'new', ['format' => 'other']],
        ['رول\s*[أا]ب|roll-?up|بانر|banner', '001', 'new', ['format' => 'rollup']],
        ['بروشور|brochure|فلاير|flyer|كتي[ّ]?ب|booklet', '001', 'new', ['format' => 'brochure']],
        ['بوستر|poster|ملصق', '001', 'new', ['format' => 'poster']],
        ['نشر[ةه]|newsletter', '001', 'new', ['format' => 'newsletter']],
        ['ستوري|story|بوست|\bpost\b|سوشال|social|منشور|كاروسيل|carousel', '001', 'new', ['format' => 'social']],
        ['استمار[ةه]|استبيان|form\b|questionnaire', '001', 'new', ['format' => 'form']],
        ['مونتاج|montage|edit\s+(the\s+)?(video|footage)', '003', 'export', []],
        // Signs are made outside ("تصميم لافتة للمعرض").
        ['لافت[ةه]|لافتات|يافط[ةه]|signage', '001', 'production', ['kind' => 'signage']],
        // "ريل" as a word only: "ابريل" (April) is not a reel.
        ['فيديو|video|(?<!\p{L})(ال)?ريل(?!\p{L})|reel', '003', 'shoot', []],
        ['تخريج|انتقاء|retouch|select\s+(the\s+)?photos', '002', 'export', []],
        ['تصوير|صور|photo', '002', 'shoot', []],
    ];

    /** Words that name the occasion or the event ("حفلة عيد الميلاد", "يوم المتطوعين", "graduation"). */
    private const OCCASION = 'عيد|ميلاد|كريسماس|christmas|فصح|easter|رأس\s*السنة|حفل|تخرج|تخريج|افتتاح|مؤتمر|ورش[ةه]|ندو[ةه]|معرض|مخيم|مهرجان|أمسي[ةه]|امسي[ةه]|لقاء|زيار[ةه]|يوم\s+ال\S+|دور[ةه]\s+\S+|event|party|graduation|conference|workshop|camp|festival|exhibition|ceremony';

    /** Month names people write, Levantine, Egyptian and English => month number. */
    private const MONTHS = [
        'كانون الثاني' => 1, 'شباط' => 2, 'آذار' => 3, 'اذار' => 3, 'نيسان' => 4, 'أيار' => 5, 'ايار' => 5, 'حزيران' => 6,
        'تموز' => 7, 'آب' => 8, 'اب' => 8, 'أيلول' => 9, 'ايلول' => 9, 'تشرين الأول' => 10, 'تشرين الاول' => 10,
        'تشرين الثاني' => 11, 'كانون الأول' => 12, 'كانون الاول' => 12,
        'يناير' => 1, 'فبراير' => 2, 'مارس' => 3, 'أبريل' => 4, 'ابريل' => 4, 'مايو' => 5, 'يونيو' => 6, 'يوليو' => 7,
        'أغسطس' => 8, 'اغسطس' => 8, 'سبتمبر' => 9, 'أكتوبر' => 10, 'اكتوبر' => 10, 'نوفمبر' => 11, 'ديسمبر' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    /**
     * @param  Collection<int, Program>  $programs
     * @param  Collection<int, City>  $cities
     * @return array{service: ?string, type: ?string, answers: array, program_id: ?int, city_id: ?int,
     *               needed_by: ?string, questions: array<int, array>, pack: Collection}
     */
    public function analyze(string $text, Collection $programs, Collection $cities): array
    {
        // Arabic-Indic digits read like Latin ones ("٢٠ كانون الأول" = "20 كانون الأول").
        $text = strtr(trim($text), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $r = ['service' => null, 'type' => null, 'answers' => [], 'program_id' => null, 'city_id' => null, 'needed_by' => null];

        foreach (self::TYPES as [$pattern, $service, $type, $answers]) {
            if ($this->matches($pattern, $text)) {
                [$r['service'], $r['type'], $r['answers']] = [$service, $type, $answers];
                break;
            }
        }

        // Nothing named, but they ask for a design: a new design of some other kind.
        if ($r['service'] === null && $this->matches('تصميم|صمم|نصمم|design', $text)) {
            [$r['service'], $r['type'], $r['answers']] = ['001', 'new', ['format' => 'other']];
        }

        $print = 'طباع|اطبع|مطبوع|نطبع|print';

        if ($r['service'] === '001') {
            $format = $r['answers']['format'] ?? null;

            // Design and print: they ask for a design, or add printing to the thing they ask for
            // ("بوستر ومطبوع 50 نسخة", "كروت مع طباعة"). "اطبع الشهادات" alone is printing a ready file.
            $addsPrint = $format !== null && $this->matches('مطبوع|(مع|و)\s*(ال)?طباع|و\s*(ا|ن|ت)طبع|and\s+print|with\s+print|printed', $text);
            if ($this->matches($print, $text) && ($addsPrint || $this->matches('تصميم|صمم|جديد|design|new', $text))
                && ! $this->matches('جاهز|عندي\s*(ال)?ملف|ready\s*file|already\s*designed', $text)) {
                // Design and print in one request ("صمّم بروشور واطبع 300 نسخة", "بوستر ومطبوع 50 نسخة").
                $r['answers']['alsoPrint'] = 'yes';
                if ($format === 'poster') {
                    $r['answers']['versions'] = ['print'];   // printing is asked for the print version
                }
                if (preg_match('/(\d[\d,]*)\s*(نسخ|نسخة|copies|copy|pcs|قطع|قطعة|كرت|كروت|بطاق)/iu', $text, $m)) {
                    $r['answers']['printQty'] = (int) str_replace(',', '', $m[1]);
                }
            } elseif ($this->matches($print, $text)) {
                $r['type'] = 'print';
                $r['answers'] = ['printItem' => [
                    'cert' => 'cert', 'poster' => 'poster', 'brochure' => 'brochure', 'rollup' => 'rollup', 'id' => 'card', 'invite' => 'card',
                ][$format] ?? 'other'];
            } elseif ($this->matches('تعديل|عدّل|عدل |غيّر|edit', $text)) {
                $r['type'] = 'edit';
                $r['answers'] = [];
            } elseif ($this->matches('ليزر|laser|ستيكر|sticker|لافت|بليكسي|plexi', $text)) {
                $r['type'] = 'production';
                $r['answers'] = ['kind' => $this->productionKind($text)];
            }
        } elseif ($r['service'] === null && $this->matches('ليزر|laser|ستيكر|sticker|بليكسي|plexi', $text)) {
            [$r['service'], $r['type'], $r['answers']] = ['001', 'production', ['kind' => $this->productionKind($text)]];
        }

        if (($r['answers']['format'] ?? null) === 'social') {
            $kinds = [];
            if ($this->matches('ستوري|story', $text)) {
                $kinds[] = 'story';
            }
            if ($this->matches('بوست|\bpost\b|منشور', $text)) {
                $kinds[] = 'post';
            }
            if ($this->matches('كاروسيل|carousel', $text)) {
                $kinds[] = 'carousel';
            }
            if ($kinds) {
                $r['answers']['socialKind'] = $kinds;
            }
        }

        foreach (config('media-requests.aliases.programs', []) as $slug => $pattern) {
            if ($this->matches($pattern, $text)) {
                $r['program_id'] = $programs->first(fn (Program $p): bool => strcasecmp((string) $p->slug, $slug) === 0)?->id;
                if ($r['program_id']) {
                    break;
                }
            }
        }

        foreach (config('media-requests.aliases.cities', []) as $slug => $pattern) {
            if ($this->matches($pattern, $text)) {
                $r['city_id'] = $cities->first(fn (City $c): bool => strcasecmp((string) $c->slug, $slug) === 0)?->id;
                if ($r['city_id']) {
                    break;
                }
            }
        }

        // What the media manager taught comes before the built-in rules; the longest match wins.
        // A lesson that points to HC Generator, HC_PACK or a reply is read by the form (lessons()).
        foreach (\App\Models\MediaAssistantLesson::matching($text) as $lesson) {
            if ($lesson->service && ! isset($taughtKind)) {
                $taughtKind = true;
                [$r['service'], $r['type']] = [$lesson->service, $lesson->type];
                $r['answers'] = $lesson->format ? ['format' => $lesson->format] : [];
            }
            if ($lesson->program_id && ! isset($taughtProgram) && $programs->contains('id', $lesson->program_id)) {
                [$taughtProgram, $r['program_id']] = [true, $lesson->program_id];
            }
            if ($lesson->city_id && ! isset($taughtCity) && $cities->contains('id', $lesson->city_id)) {
                [$taughtCity, $r['city_id']] = [true, $lesson->city_id];
            }
        }

        $r['needed_by'] = $this->date($text);
        // A shoot's date is the day of the event.
        if ($r['needed_by'] && $r['type'] === 'shoot') {
            $r['answers']['eventDate'] = $r['needed_by'];
        }
        $r['questions'] = $r['service'] ? $this->questions($r) : [];

        // Nothing is asked that the text already answered: the occasion (their words go to the team as
        // the brief) or an answer read from it (the event's date).
        $occasion = $this->matches(self::OCCASION, $text);
        $r['questions'] = array_values(array_filter($r['questions'], fn (array $q): bool => ! ($occasion && in_array($q['id'], ['occasion', 'topic', 'event'], true))
            && ! (is_string($q['target']) && str_starts_with($q['target'], 'answers.') && filled($r['answers'][substr($q['target'], 8)] ?? null))));
        $r['pack'] = collect();

        return $r;
    }

    /** @return array<int, array{id: string, ar: string, en: string, target: ?string, kind: string}> */
    private function questions(array $r): array
    {
        $q = fn (string $id, string $ar, string $en, ?string $target = null, string $kind = 'text') => compact('id', 'ar', 'en', 'target', 'kind');

        $sets = [
            'cert' => [
                $q('occasion', 'ما اسم الدورة أو المناسبة؟', 'What is the course or occasion?', 'title'),
                $q('trainer', 'ما اسم المدرب؟', "What is the trainer's name?"),
                $q('signer', 'من سيوقّع الشهادة، وما منصبه؟', 'Who signs it, and what is their title?'),
                $q('count', 'كم شهادة؟ وهل توجد قائمة بالأسماء؟', 'How many certificates? Is there a list of names?'),
            ],
            'id' => [
                $q('idFor', 'أسماء أصحاب البطاقات ومناصبهم (كل اسم في سطر)', 'Names and roles for the cards (one per line)', 'answers.idFor', 'textarea'),
            ],
            'invite' => [
                $q('occasion', 'ما المناسبة؟', 'What is the occasion?', 'title'),
                $q('when', 'تاريخ المناسبة ومكانها', 'Date and place of the event'),
                $q('medium', 'مطبوعة أم إلكترونية؟', 'Printed or digital?'),
            ],
            'social' => [
                $q('topic', 'عن ماذا يتحدث المنشور؟', 'What is the post about?', 'title'),
                $q('where', 'أين سيُنشر؟ (فيسبوك، إنستغرام...)', 'Where will it be published?'),
            ],
            'design' => [
                $q('topic', 'ما الموضوع أو المناسبة؟', 'What is the topic or occasion?', 'title'),
                $q('text', 'ما النص أو المعلومات التي يجب أن تُكتب؟', 'What text or information must it include?'),
            ],
            'shoot' => [
                $q('event', 'ما الفعالية؟', 'What is the event?', 'title'),
                $q('eventDate', 'متى الفعالية؟', 'When is the event?', 'answers.eventDate', 'date'),
                $q('place', 'أين؟', 'Where?', 'answers.place'),
            ],
            'export' => [
                $q('event', 'من أي فعالية؟', 'From which event?', 'title'),
                $q('purpose', 'لأي غرض تحتاجها؟', 'What are they for?'),
            ],
        ];

        $key = $r['type'] === 'new'
            ? (['cert' => 'cert', 'id' => 'id', 'invite' => 'invite', 'social' => 'social'][$r['answers']['format'] ?? ''] ?? 'design')
            : ($r['service'] === '001' ? 'design' : $r['type']);

        $questions = $sets[$key] ?? $sets['design'];

        if (! $r['program_id']) {
            $questions[] = $q('program', 'لأي برنامج الطلب؟', 'Which program is it for?', 'program_id', 'program');
        }
        if (! $r['city_id']) {
            $questions[] = $q('city', 'في أي محافظة؟', 'Which governorate?', 'city_id', 'city');
        }
        if (! $r['needed_by']) {
            $questions[] = $q('deadline', 'متى تحتاجه؟', 'When do you need it?', 'needed_by', 'date');
        }

        return $questions;
    }

    private function date(string $text): ?string
    {
        return match (true) {
            $this->matches('بعد\s*بكر[اة]|day after tomorrow', $text) => today()->addDays(2)->toDateString(),
            $this->matches('بكر[اة]|tomorrow', $text) => today()->addDay()->toDateString(),
            $this->matches('الأسبوع الجاي|الاسبوع الجاي|الجمعة الجاية|next week', $text) => today()->addWeek()->toDateString(),
            default => $this->namedDate($text),
        };
    }

    /**
     * A day written out: "20 كانون الأول", "20 ديسمبر", "December 20", "20/12" (day first).
     * Without a year, the next such day from today.
     */
    private function namedDate(string $text): ?string
    {
        // Longest first, and a whole word, so "اب" (August) never takes "ابريل" (April).
        $keys = array_keys(self::MONTHS);
        usort($keys, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $names = '(?:'.implode('|', array_map(fn ($m) => str_replace(' ', '\s+', preg_quote($m, '/')), $keys)).')(?!\p{L})';
        $day = $month = $year = null;

        if (preg_match('/(?<!\d)(\d{1,2})\s*(?:من\s*)?(?:شهر\s*)?('.$names.')(?:\s+(\d{4}))?/iu', $text, $m)) {
            [$day, $month, $year] = [(int) $m[1], $this->month($m[2]), isset($m[3]) ? (int) $m[3] : null];
        } elseif (preg_match('/('.$names.')\s+(\d{1,2})(?!\d)(?:,?\s+(\d{4}))?/iu', $text, $m)) {
            [$day, $month, $year] = [(int) $m[2], $this->month($m[1]), isset($m[3]) ? (int) $m[3] : null];
        } elseif (preg_match('/(?<![\d\/.-])(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?(?![\d\/.-])/u', $text, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], isset($m[3]) ? (int) (strlen($m[3]) === 2 ? '20'.$m[3] : $m[3]) : null];
        }

        if (! $day || ! $month || ! checkdate($month, $day, $year ?? (int) today()->year)) {
            return null;
        }

        $date = today()->setDate($year ?? (int) today()->year, $month, $day);
        if ($year === null && $date->lt(today())) {
            $date->addYear();
        }

        return $date->toDateString();
    }

    private function month(string $name): ?int
    {
        $name = mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));

        return self::MONTHS[$name] ?? null;
    }

    private function productionKind(string $text): string
    {
        return match (true) {
            $this->matches('ليزر|laser', $text) => 'laser',
            $this->matches('ستيكر|sticker', $text) => 'stickers',
            $this->matches('بليكسي|plexi', $text) => 'plexi',
            default => 'signage',
        };
    }

    private function matches(string $pattern, string $text): bool
    {
        return preg_match('/'.$pattern.'/iu', $text) === 1;
    }
}
