<?php

/*
|--------------------------------------------------------------------------
| Media Requests
|--------------------------------------------------------------------------
|
| Who the Media team is, who approves, and the questions each kind of
| request asks. Labels for every key below live in lang/{ar,en}/media.php
| (f.<field>, o.<field>.<option>, h.<field>, ph.<field>).
|
*/

return [

    // The Communication department. Everyone with an active contract on a
    // position in this department is on the Media team.
    'media_department_code' => env('MEDIA_DEPARTMENT_CODE', 'CT'),

    // Job-level grades inside the media department that act as media manager
    // (approve, check work, assign). 5 = Department Manager.
    'media_manager_grades' => [1, 2, 3, 4, 5],

    // Job-level grades that count as the manager of a program. A program is
    // country-wide, so the program manager is found by program only, not city.
    'program_manager_grades' => [1],

    // An open request waiting this long with the same owner is flagged late.
    'late_after_hours' => 24,

    // A reminder ("nudge") can be sent once per this many hours.
    'nudge_every_hours' => 24,

    // Used only when the request type is not known yet: a needed-by date this close needs a rush reason.
    'rush_days' => 2,

    /*
    | When the media team works, and how long each kind of request takes.
    | Deadlines count only working days: a request sent on Friday at 16:30
    | starts on Monday. Anything done outside (a print shop, the market) also
    | needs the market open, and it closes on Friday, so outside days are
    | Monday to Thursday only.
    |
    | "turnaround": request type => [team working days, outside days].
    | A date earlier than this is a rush and needs a reason.
    */
    'work' => [
        'timezone' => 'Asia/Damascus',
        'days' => [1, 2, 3, 4, 5],          // ISO weekdays: Monday to Friday
        'from' => '09:00',
        'to' => '17:00',
        'same_day_until' => '13:00',        // sent before this on a working day, that day counts
        'outside_days' => [1, 2, 3, 4],     // Monday to Thursday: the team works and the market is open
        'turnaround' => [
            '001.new' => [4, 0],            // new design
            '001.edit' => [1, 0],           // small change to an existing design
            '001.print' => [1, 2],          // print an existing design: prepare the file, then the print shop
            '001.production' => [2, 5],     // plexi, laser, signage…: design, then the workshop
            '002.shoot' => [2, 0],          // photos ready after the event
            '002.export' => [2, 0],         // selecting and retouching photos
            '003.shoot' => [3, 0],          // filming, first cut
            '003.export' => [5, 0],         // video editing
            '000.pack' => [1, 0],           // a ready file from HC_PACK
        ],
        'print_with_design' => 2,           // outside days added when a new design is also printed
        /*
        | Team load. Each open request has a rough size ("effort", 1 = a poster);
        | each person can carry about "capacity" of it at once. When the people
        | of a specialty carry more, a new request's default date moves out by
        | the days it would wait, at most "max_extra_days".
        */
        'capacity' => 2,
        'max_extra_days' => 5,
        'effort' => [
            '001.new' => ['default' => 1, 'format' => [
                'newsletter' => 3, 'brochure' => 2, 'rollup' => 1, 'poster' => 1, 'social' => 1,
                'cert' => 1, 'id' => 1, 'invite' => 1, 'form' => 1, 'cover' => 0.5, 'other' => 1,
            ], 'booklet' => 3],
            '001.edit' => 0.5,
            '001.print' => 0.5,
            '001.production' => 1,
            '002.shoot' => 1,
            '002.export' => 1,
            '003.shoot' => 2,
            '003.export' => 3,
            '000.pack' => 0,
        ],
    ],

    /*
    | Proofreader (home screen and request form): local punctuation fixes always;
    | spelling and grammar by Gemini when a key is set. Instructions: resources/prompts/proofread.md.
    */
    'proofread' => [
        'key' => env('GEMINI_API_KEY', ''),
        // Tried in order: fast and enough for short texts first, the bigger one if it is busy or down.
        // The "-latest" names follow Google's current models, so they keep working when old ones retire.
        'models' => array_filter(explode(',', (string) env('GEMINI_MODEL', 'gemini-flash-lite-latest,gemini-flash-latest'))),
        'daily_limit' => 40,   // AI checks per person per day
        // The team's own Gemini Gem (gemini.google.com/gem/...): a button copies the text and opens it.
        // Google does not allow it inside another page, so it opens in a new tab. Set GEMINI_GEM_URL= (empty) to hide it.
        'gem_url' => env('GEMINI_GEM_URL', 'https://gemini.google.com/gem/1d2AX7yQRcwnA2HjrkAixmFY-aDerNRFQ?usp=sharing'),
    ],
    // HC_PACK index: one [path, bytes] pair per file, relative to the pack root.
    // Rebuild with: php artisan media:pack-index "D:\000_HC_PACK_012025"
    'pack_index' => resource_path('data/hc-pack.json'),

    'services' => [
        '001' => ['key' => 'design', 'types' => ['new', 'edit', 'print', 'production']],
        '002' => ['key' => 'photo', 'types' => ['shoot', 'export']],
        '003' => ['key' => 'video', 'types' => ['shoot', 'export']],
        '000' => ['key' => 'pack', 'types' => ['pack']],
    ],

    /*
    | The Media team's specialties. Everyone can do everything, but each person
    | has their own field: a request shows first to the people of its field and
    | they are suggested when it is assigned.
    |
    | "for": which request types belong to the field.
    | "title": how the field shows in a position title, e.g. the HR position
    | "Communication team member / Photographer". A title can name several.
    */
    'specialties' => [
        'design' => ['icon' => 'pen', 'for' => ['001' => '*', '000' => '*'],      // design and print
            'title' => 'designer|design|مصمم|تصميم'],
        'photo' => ['icon' => 'camera', 'for' => ['002' => '*'],                  // photography
            'title' => 'photographer|photography|مصور(?!\s*فيديو)'],
        'video' => ['icon' => 'video', 'for' => ['003' => ['shoot']],             // filming
            'title' => 'videographer|video|فيديو|مصور\s*فيديو'],
        'montage' => ['icon' => 'layers', 'for' => ['003' => ['export']],         // video editing
            'title' => 'videographer|video\s*editor|editor|montage|مونتاج|مونتير|محرر\s*فيديو|مصور\s*فيديو'],
    ],

    'priorities' => ['high', 'normal', 'low'],

    'delivery_channels' => ['telegram', 'email', 'folder', 'hand'],

    /*
    | Questions per "service.type".
    |
    | type:      chips (one), checks (many), yesno, check (tick box), number,
    |            text, textarea, date, time, select
    | required:  must be answered when visible
    | extra:     optional, shown on the last step ("files and extras")
    | full:      takes the full row
    | pics:      draw a small picture for each option. true = picture named
    |            like the option; an array maps option => picture name.
    |            'sizes' draws paper outlines to scale, 'invite' draws how many
    |            cards come out of one A4 sheet.
    | show_if:   all conditions must hold. [field, op, value] with op
    |            in (one of), has (list contains), filled.
    |            ['any' => [[...], [...]]] holds when any group holds.
    */
    'fields' => (function (): array {
        $yn = ['yes', 'no'];
        $usage = fn (bool $extra) => [
            ['key' => 'usage', 'type' => 'checks', 'options' => ['social', 'linkedin', 'report', 'website', 'print'], 'full' => true, 'extra' => $extra],
            ['key' => 'reportFor', 'type' => 'text', 'required' => true, 'full' => true, 'extra' => $extra, 'show_if' => [['usage', 'has', 'report']]],
        ];
        $consent = [
            ['key' => 'children', 'type' => 'yesno', 'required' => true, 'options' => $yn, 'full' => true],
            ['key' => 'consent', 'type' => 'check', 'required' => true, 'full' => true, 'show_if' => [['children', 'in', ['yes']]]],
        ];
        $times = [
            ['key' => 'start', 'type' => 'time', 'extra' => true],
            ['key' => 'end', 'type' => 'time', 'extra' => true],
        ];
        $videoOut = [
            ['key' => 'duration', 'type' => 'chips', 'required' => true, 'options' => ['15s', '30s', '60s', '3m', 'long']],
            ['key' => 'orientation', 'type' => 'chips', 'required' => true, 'pics' => ['both' => 'bothOr'], 'options' => ['vertical', 'horizontal', 'both']],
            ['key' => 'platforms', 'type' => 'checks', 'options' => ['facebook', 'instagram', 'linkedin', 'whatsapp'], 'full' => true],
            ['key' => 'voiceover', 'type' => 'chips', 'options' => ['none', 'ar', 'en', 'both']],
            ['key' => 'subtitles', 'type' => 'chips', 'options' => ['none', 'ar', 'en', 'both'], 'extra' => true],
            ['key' => 'lightCopy', 'type' => 'check', 'full' => true, 'extra' => true],
        ];
        $invitePrint = [['inviteMedium', 'in', ['print', 'both']], ['format', 'in', ['invite']]];
        // Designs that end up on paper (or plastic), so printing can be asked for with them.
        $printable = [
            [['format', 'in', ['brochure', 'rollup', 'id', 'cert']]],
            [['format', 'in', ['poster']], ['versions', 'has', 'print']],
            [['format', 'in', ['newsletter']], ['newsMedium', 'has', 'print']],
            [['format', 'in', ['form']], ['formOutput', 'has', 'print']],
            $invitePrint,
        ];

        return [
            '001.new' => [
                ['key' => 'format', 'type' => 'chips', 'required' => true, 'pics' => ['brochure' => 'trifold'],
                    'options' => ['social', 'poster', 'brochure', 'newsletter', 'rollup', 'id', 'cert', 'invite', 'cover', 'form', 'other']],
                ['key' => 'socialKind', 'type' => 'checks', 'required' => true, 'full' => true, 'pics' => true,
                    'options' => ['post', 'story', 'carousel'], 'show_if' => [['format', 'in', ['social']]]],
                ['key' => 'slides', 'type' => 'number', 'required' => true,
                    'show_if' => [['format', 'in', ['social']], ['socialKind', 'has', 'carousel']]],
                ['key' => 'platforms', 'type' => 'checks', 'options' => ['facebook', 'instagram', 'linkedin', 'whatsapp'], 'full' => true,
                    'show_if' => [['format', 'in', ['social']]]],
                ['key' => 'versions', 'type' => 'checks', 'required' => true, 'full' => true,
                    'pics' => ['print' => 'poster', 'square' => 'post', 'story' => 'story', 'linkedin' => 'wide', 'mail' => 'mailpic'],
                    'options' => ['print', 'square', 'story', 'linkedin', 'mail'], 'show_if' => [['format', 'in', ['poster']]]],
                ['key' => 'posterSize', 'type' => 'chips', 'required' => true, 'pics' => 'sizes', 'options' => ['A4', 'A3', 'A2', 'A1', 'custom'],
                    'show_if' => [['format', 'in', ['poster']], ['versions', 'has', 'print']]],
                ['key' => 'newsMedium', 'type' => 'checks', 'required' => true, 'full' => true,
                    'pics' => ['print' => 'booklet', 'interactive' => 'phone'], 'options' => ['print', 'interactive'],
                    'show_if' => [['format', 'in', ['newsletter']]]],
                ['key' => 'pages', 'type' => 'number', 'required' => true,
                    'show_if' => ['any' => [[['format', 'in', ['newsletter']]], [['format', 'in', ['brochure']], ['brochureType', 'in', ['booklet']]]]]],
                ['key' => 'issue', 'type' => 'text', 'show_if' => [['format', 'in', ['newsletter']]]],
                ['key' => 'coverKind', 'type' => 'checks', 'required' => true, 'full' => true,
                    'pics' => ['youtube' => 'youtube', 'gform' => 'gform', 'profile' => 'profile', 'page' => 'cover'],
                    'options' => ['youtube', 'gform', 'profile', 'page'], 'show_if' => [['format', 'in', ['cover']]]],
                ['key' => 'coverCount', 'type' => 'number', 'show_if' => [['format', 'in', ['cover']]]],
                ['key' => 'formKind', 'type' => 'chips', 'required' => true,
                    'pics' => ['questionnaire' => 'formpic', 'application' => 'formpic', 'consent' => 'sheet', 'other' => 'other'],
                    'options' => ['questionnaire', 'application', 'consent', 'other'], 'show_if' => [['format', 'in', ['form']]]],
                ['key' => 'formOutput', 'type' => 'checks', 'required' => true, 'full' => true, 'options' => ['print', 'word', 'online'],
                    'show_if' => [['format', 'in', ['form']]]],
                ['key' => 'brochureType', 'type' => 'chips', 'required' => true, 'pics' => true,
                    'options' => ['flyer', 'bifold', 'trifold', 'booklet'], 'show_if' => [['format', 'in', ['brochure']]]],
                ['key' => 'brochureSize', 'type' => 'chips', 'pics' => 'sizes', 'options' => ['A5', 'A4', 'DL'],
                    'show_if' => [['format', 'in', ['brochure']]]],
                ['key' => 'rollupSize', 'type' => 'chips', 'required' => true, 'pics' => ['custom' => 'banner'],
                    'options' => ['r80', 'r85', 'r100', 'custom'], 'show_if' => [['format', 'in', ['rollup']]]],
                ['key' => 'idMaterial', 'type' => 'chips', 'required' => true, 'pics' => true,
                    'options' => ['cardboard', 'plastic'], 'show_if' => [['format', 'in', ['id']]]],
                ['key' => 'idFor', 'type' => 'textarea', 'required' => true, 'full' => true, 'show_if' => [['format', 'in', ['id']]]],
                ['key' => 'certNames', 'type' => 'chips', 'pics' => true, 'options' => ['blank', 'names'],
                    'show_if' => [['format', 'in', ['cert']]]],
                ['key' => 'inviteMedium', 'type' => 'chips', 'required' => true,
                    'pics' => ['print' => 'sheet', 'digital' => 'phone', 'both' => 'phoneSheet'],
                    'options' => ['print', 'digital', 'both'], 'show_if' => [['format', 'in', ['invite']]]],
                ['key' => 'inviteSize', 'type' => 'chips', 'required' => true, 'pics' => 'invite', 'wide' => true,
                    'options' => ['dl', 'a6', 'a5', 'folded', 'square', 'custom'], 'show_if' => $invitePrint],
                ['key' => 'envelope', 'type' => 'chips', 'required' => true, 'pics' => ['yes' => 'envelope', 'no' => 'sheet'],
                    'options' => ['yes', 'no'], 'show_if' => $invitePrint],
                ['key' => 'customSize', 'type' => 'text', 'required' => true, 'show_if' => ['any' => [
                    [['format', 'in', ['poster']], ['posterSize', 'in', ['custom']]],
                    [['format', 'in', ['rollup']], ['rollupSize', 'in', ['custom']]],
                    [['format', 'in', ['invite']], ['inviteSize', 'in', ['custom']], ['inviteMedium', 'in', ['print', 'both']]],
                    [['format', 'in', ['other']]],
                ]]],
                ['key' => 'langs', 'type' => 'checks', 'required' => true, 'options' => ['ar', 'en'], 'full' => true, 'default' => ['ar'],
                    'show_if' => [['format', 'filled', null]]],
                // A design that can be printed may also need printing: one request for both.
                // If the budget is not known, the media team sends a price quote first.
                ['key' => 'alsoPrint', 'type' => 'yesno', 'required' => true, 'options' => $yn, 'full' => true, 'show_if' => ['any' => $printable]],
                ['key' => 'printQty', 'type' => 'number', 'required' => true, 'show_if' => [['alsoPrint', 'in', ['yes']]]],
                ['key' => 'printColor', 'type' => 'chips', 'options' => ['color', 'bw'], 'default' => 'color', 'show_if' => [['alsoPrint', 'in', ['yes']]]],
                ['key' => 'budget', 'type' => 'chips', 'required' => true, 'full' => true, 'options' => ['known', 'quote'],
                    'show_if' => [['alsoPrint', 'in', ['yes']]]],
                ['key' => 'budgetAmount', 'type' => 'text', 'required' => true,
                    'show_if' => [['alsoPrint', 'in', ['yes']], ['budget', 'in', ['known']]]],
                ['key' => 'logos', 'type' => 'text', 'extra' => true],
                ['key' => 'contentChecked', 'type' => 'yesno', 'options' => $yn, 'full' => true, 'extra' => true, 'default' => 'yes'],
                ['key' => 'contentNote', 'type' => 'textarea', 'required' => true, 'full' => true, 'extra' => true,
                    'show_if' => [['contentChecked', 'in', ['no']]]],
            ],
            '001.edit' => [
                ['key' => 'editWhat', 'type' => 'checks', 'required' => true, 'options' => ['text', 'dates', 'names', 'images', 'logos', 'size'], 'full' => true],
                ['key' => 'source', 'type' => 'chips', 'options' => ['attached', 'archive', 'unknown']],
                ['key' => 'archiveRef', 'type' => 'text', 'show_if' => [['source', 'in', ['archive']]]],
            ],
            '001.print' => [
                ['key' => 'printItem', 'type' => 'chips', 'required' => true, 'pics' => ['brochure' => 'trifold'],
                    'options' => ['flyer', 'poster', 'cert', 'card', 'brochure', 'rollup', 'other']],
                ['key' => 'qty', 'type' => 'number', 'required' => true],
                ['key' => 'paperSize', 'type' => 'chips', 'required' => true, 'pics' => 'sizes', 'options' => ['A5', 'A4', 'A3', 'custom'],
                    'show_if' => [['printItem', 'filled', null], ['printItem', 'not', ['rollup']]]],
                ['key' => 'customSize', 'type' => 'text', 'required' => true,
                    'show_if' => ['any' => [[['paperSize', 'in', ['custom']]], [['printItem', 'in', ['rollup']]]]]],
                ['key' => 'color', 'type' => 'chips', 'required' => true, 'options' => ['color', 'bw'], 'default' => 'color'],
                ['key' => 'paper', 'type' => 'chips', 'options' => ['standard', 'thick', 'cardboard', 'sticker'], 'show_if' => [['printItem', 'not', ['rollup']]]],
                ['key' => 'sides', 'type' => 'chips', 'options' => ['one', 'two'], 'show_if' => [['printItem', 'not', ['rollup']]]],
                ['key' => 'where', 'type' => 'select', 'options' => ['team', 'inhouse', 'outside'], 'extra' => true],
            ],
            '001.production' => [
                ['key' => 'kind', 'type' => 'chips', 'required' => true, 'options' => ['plexi', 'laser', 'banner', 'signage', 'fabric', 'stickers', 'merch', 'other']],
                ['key' => 'qty', 'type' => 'number', 'required' => true],
                ['key' => 'size', 'type' => 'text'],
                ['key' => 'install', 'type' => 'yesno', 'options' => $yn, 'show_if' => [['kind', 'in', ['plexi', 'banner', 'signage']]]],
                ['key' => 'material', 'type' => 'text', 'extra' => true],
                ['key' => 'quote', 'type' => 'check', 'full' => true, 'extra' => true],
            ],
            '002.shoot' => [
                ['key' => 'coverage', 'type' => 'chips', 'required' => true, 'options' => ['event', 'session', 'visit', 'portraits', 'project', 'other']],
                ['key' => 'eventDate', 'type' => 'date', 'required' => true],
                ['key' => 'place', 'type' => 'text', 'required' => true],
                ['key' => 'people', 'type' => 'number', 'required' => true, 'show_if' => [['coverage', 'in', ['portraits']]]],
                ...$consent, ...$times, ...$usage(true),
            ],
            '002.export' => [
                ['key' => 'deliverWhat', 'type' => 'chips', 'required' => true, 'options' => ['select', 'retouch', 'all']],
                ['key' => 'count', 'type' => 'number'],
                ...$usage(false),
            ],
            '003.shoot' => [
                ['key' => 'videoKind', 'type' => 'chips', 'required' => true, 'options' => ['event', 'interview', 'promo', 'story', 'other']],
                ['key' => 'eventDate', 'type' => 'date', 'required' => true],
                ['key' => 'place', 'type' => 'text', 'required' => true],
                ...$consent, ...$videoOut, ...$times,
            ],
            '003.export' => [
                ['key' => 'editKind', 'type' => 'checks', 'required' => true, 'full' => true, 'options' => ['highlights', 'full', 'clips', 'subtitles']],
                ['key' => 'clipCount', 'type' => 'number', 'required' => true, 'show_if' => [['editKind', 'has', 'clips']]],
                ...$usage(false), ...$videoOut,
            ],
        ];
    })(),

    /*
    | How people refer to programs and cities in free text, for the request
    | assistant. Keys are program / city slugs as stored in the HR app.
    */
    'aliases' => [
        'programs' => [
            'DISC' => 'الديسك|ديسك|مركز التدريب|\bdisc\b',
            'SZ' => 'ستادي|study\s*zone|\bsz\b',
            'CU' => '[أا]كاديمي[ةه] ال[أا]طفال|جامع[ةه] ال[أا]طفال|children\s*university|\bcu\b',
            'LEAP' => 'ليب|\bleap\b',
            'CYG' => '\bcyg\b',
            'MP' => 'مشاريع صغيرة|المشاريع الصغيرة|micro\s*projects?|\bmp\b',
            'RLF' => 'إغاثة|اغاثة|relief|\brlf\b',
            'F2F' => 'عائلة لعائلة|family\s*to\s*family|\bf2f\b',
            'S2S' => 'مدرسة لمدرسة|school\s*to\s*school|\bs2s\b',
            'HWR' => 'غرفة الوظائف|homework|\bhwr\b',
            'ESP' => 'ريادة|entrepreneur|\besp\b',
            'HC' => 'مركز الرجاء|hope\s*center|\bhc\b',
        ],
        'cities' => [
            'RID' => 'ريف دمشق|damascus countryside|rif dimashq',
            'ALP' => 'حلب|aleppo',
            'HMS' => 'حمص|homs',
            'DMS' => 'دمشق|الشام|damascus',
            'LAT' => 'اللاذقية|اللادقية|latakia',
            'TAR' => 'طرطوس|tartus',
            'HAM' => 'حماة|حماه|hama',
            'DAR' => 'درعا|daraa',
            'SWD' => 'السويداء|suwayda|suwaida',
            'QNT' => 'القنيطرة|quneitra|qounaitra',
            'RQA' => 'الرقة|raqqa',
            'DZR' => 'دير الزور|deir',
            'HSK' => 'الحسكة|hasakah',
            'IDL' => 'إدلب|ادلب|idlib|idleb',
        ],
    ],

    /*
    | HC Generator: the centre's do-it-yourself site (posters from project
    | templates, reels, thumbnails, proofreading, transcription). When a
    | request is something a tool there can make, the form points to it first.
    | Tools are checked in order, most specific first; "unless" skips a tool
    | (a poster to print still needs the media team).
    */
    'generator' => [
        'url' => env('HC_GENERATOR_URL', 'https://post-gen.hcsyria-apps.org'),
        'tools' => [
            'meeting' => ['path' => '/meeting-transcription/', 'icon' => 'msg',
                'match' => '(تفريغ|فر[ّ]?غ\S*)\s*(ال)?(اجتماع|تسجيل|مقابل|محاضر|صوت|ريكورد)|محضر|transcri\w*\s+(the\s+|a\s+)?(meeting|recording|audio|interview)|meeting\s+minutes'],
            'text' => ['path' => '/text-transcription/', 'icon' => 'file',
                'match' => '(استخرا?ج|طل[ّ]?ع|نسخ)\s*(لي\s*)?(ال)?نص|نص.{0,20}(صور[ةه]|pdf)|فواتير|فاتور[ةه]|invoice|\bocr\b|extract\s+(the\s+)?text|خانات'],
            'guidelines' => ['path' => '/guideline-assistant/', 'icon' => 'check',
                'match' => 'دق[ّ]?ق|تدقيق|ترويس|proof-?read|letterhead|(تنسيق|نس[ّ]?ق).{0,20}(مستند|تقرير|كتاب|ملف)'],
            'thumbnail' => ['path' => '/thumbnail-gen/', 'icon' => 'image',
                'match' => 'thumbnail|ثمبنيل|صور[ةه]\s*مصغ[ّ]?ر|غلاف.{0,15}(فيديو|يوتيوب|youtube)|youtube\s+cover',
                'types' => [['001', 'new', 'format', 'cover', 'coverKind', 'youtube']]],
            'reels' => ['path' => '/reels-gen/', 'icon' => 'video',
                'match' => 'ريل|reel|فيديو\s*قصير|short\s+video|تيك\s*توك|tik\s*tok|مونتاج\s*(ال)?صور|لخ[ّ]?ص.{0,25}(فيديو|محاضر|مقابل)'],
            'poster' => ['path' => '/', 'icon' => 'layers',
                'match' => 'بوستر|poster|ستوري|story|بوست|\bpost\b|منشور|[إا]علان|شاغر|vacancy|announcement',
                'unless' => 'طباع|اطبع|print|رول\s*[أا]ب|roll-?up|بانر|banner',
                'types' => [['001', 'new', 'format', 'poster'], ['001', 'new', 'format', 'social']]],
        ],
    ],
];
