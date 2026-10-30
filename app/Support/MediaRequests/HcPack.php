<?php

namespace App\Support\MediaRequests;

use App\Models\Program;
use Illuminate\Support\Collection;

/**
 * HC_PACK: the official logos and ready templates. Many "requests" are just
 * somebody who needs a file that already exists here, so the request form
 * searches it first and offers the file instead of a new design job.
 *
 * The index is a plain list of [relative path, bytes] built from the folder
 * (php artisan media:pack-index). The files themselves stay on the shared
 * drive; the team sends them, and the request records who asked and when.
 * The media manager can also add, replace and take out files in the app
 * (media_pack_files); those changes sit over the index, and an added or
 * replaced file is kept in the app, so the team downloads it from there.
 *
 * Where a file is there in more than one version ("logo.svg" and "logo (2).svg",
 * "… v2", "… new", "… 2024"), only the newest is offered.
 */
class HcPack
{
    /** [group, folder prefix, keywords] */
    public const GROUPS = [
        ['projects', '001 Logos/001 Projects', 'logo شعار لوغو برنامج program المؤسسة الهوب hope الرجاء'],
        ['donors', '001 Logos/002 Donors', 'donor donors ممول الممول ممولين الممولين مانح المانح داعم الداعم'],
        ['parish', '001 Logos/003 Parish', 'رعية الرعية كنيسة church parish'],
        ['cop', '001 Logos/004 COP', 'cop'],
        ['orgs', '001 Logos/005 Other', 'منظمة organisation organization caritas كاريتاس jrs barnabas dorcas open doors'],
        ['guides', '004 Template/000 GuideLine Books', 'دليل الهوية guideline guide brand هوية بصرية'],
        ['id', '004 Template/001 ID', 'id بطاقة بطاقات كرت هوية badge card'],
        ['signature', '004 Template/002 Signature', 'توقيع signature ايميل إيميل email'],
        ['letterhead', '004 Template/003 Formal Papers', 'ورق رسمي ورقة رسمية ترويسة letterhead formal paper كتاب رسمي'],
        ['mugs', '004 Template/004 Mugs', 'كاسة كاسات مج mug'],
        ['rollup', '004 Template/005 Roll_Up', 'رول أب رول اب rollup roll-up roll'],
        ['plexi', '004 Template/006 Plexi + Stickers', 'بليكسي ستيكر ستيكرات لصاقة plexi sticker لوحة'],
        ['notebook', '004 Template/007 Note Book', 'دفتر نوت بوك notebook bloc'],
        ['cert', '004 Template/009 Certificate', 'شهادة شهادات certificate'],
        ['consent', '004 Template/010 Written Consent', 'موافقة consent written'],
        ['trophies', '004 Template/011 Trophies', 'درع دروع كأس trophy'],
        ['map', '004 Template/012 Map', 'خريطة خارطة map سوريا syria'],
        ['flyers', '004 Template/013 Flyers', 'فلاير flyer'],
        ['qr', '004 Template/014 Projects QR', 'qr كيو باركود'],
        ['social', '004 Template/015 Social Media', 'سوشال highlights هايلايت ستوري story'],
        ['wallpaper', '004 Template/016 Wallpaper', 'خلفية خلفيات wallpaper background ميلاد كريسماس christmas فصح easter'],
        ['other', '004 Template/017 Other', 'ألوان colors color crosses صلبان thumbnail'],
        ['word', '004 Template/018 Word Document', 'وورد word قالب document'],
    ];

    /** Donor names people type => the code used in file names. */
    private const DONORS = [
        '/كاريتاس|caritas\s*polska|\bcp\b/iu' => 'CP',
        '/oeuvre|œuvre|أوفر|\bld\b/iu' => 'LD',
        '/\bacn\b/iu' => 'ACN',
        '/missio|ميسيو/iu' => 'MI',
        '/hungary|هنغاري/iu' => 'HH',
        '/kerk|\bkia\b/iu' => 'KIA',
        '/\bsalt\b|سولت/iu' => 'SALT',
        '/nichol|نيكولا/iu' => 'SN',
        '/polish|بولند|\bpa\b/iu' => 'PA',
        '/\bmecc\b|مجلس كنائس/iu' => 'MECC',
        '/\bmcc\b|mennonite/iu' => 'MCC',
        '/paris|باريس/iu' => 'VP',
        '/cdcs/iu' => 'CDCS',
        '/syriac|سرياني/iu' => 'SC',
        '/region sud|région sud/iu' => 'RS',
        '/\bgiz\b/iu' => 'GIZ',
    ];

    private const EXT_RANK = ['svg' => 0, 'png' => 1, 'pdf' => 2, 'jpg' => 3, 'docx' => 4, 'idml' => 5, 'indd' => 6, 'ai' => 7, 'cdr' => 8];

    /** @var Collection<int, array{path: string, size: int, name: string, dir: string, group: string, ext: string, low: string}>|null */
    private ?Collection $files = null;

    /** @return Collection<int, array{path: string, size: int, name: string, dir: string, group: string, ext: string, low: string}> */
    public function all(): Collection
    {
        if ($this->files !== null) {
            return $this->files;
        }

        return $this->files = $this->newestOnly($this->everything()->reject(fn (array $f) => $f['removed']));
    }

    /**
     * Every file the pack has had: the drive's index with the app's changes over it (a removed
     * one marked so; requests made earlier still name it).
     *
     * @return Collection<int, array>
     */
    public function everything(): Collection
    {
        if (isset($this->memo)) {
            return $this->memo;
        }

        $path = config('media-requests.pack_index');
        $rows = is_string($path) && is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $files = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row[0] ?? null)) {
                $files[str_replace('\\', '/', $row[0])] = $this->entry(str_replace('\\', '/', $row[0]), (int) ($row[1] ?? 0));
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('media_pack_files')) {
            foreach (\App\Models\MediaPackFile::query()->get() as $change) {
                $files[$change->path] = $this->entry($change->path, $change->size, $change);
            }
        }

        return $this->memo = collect(array_values($files));
    }

    private ?Collection $memo = null;

    private function entry(string $path, int $size, ?\App\Models\MediaPackFile $change = null): array
    {
        $parts = explode('/', $path);
        $name = (string) array_pop($parts);

        return [
            'path' => $path,
            'size' => $size,
            'name' => $name,
            'dir' => implode(' / ', $parts),
            'group' => collect(self::GROUPS)->first(fn (array $g): bool => str_starts_with($path, $g[1].'/'))[0] ?? 'other',
            'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'low' => mb_strtolower($path),
            'id' => $change?->stored ? $change->id : null,   // kept in the app: downloaded from there
            'changed' => $change?->updated_at,
            'removed' => (bool) $change?->removed,
            'older' => 0,
        ];
    }

    /**
     * One file per set of versions: the newest. A set is the same folder, kind and name once its
     * last version mark is taken off ("(2)", "v2", "copy", "new", "final", a year, a date).
     * Only a real set: the plain name beside a marked one, or names with marks other than "(n)";
     * a numbered series with no plain name ("Xmas (1)" … "Xmas (12)") is twelve different files.
     * Newest: changed in the app most recently, else the highest mark.
     */
    private function newestOnly(Collection $files): Collection
    {
        $sets = $files->groupBy(fn (array $f) => $f['dir'].'|'.$f['ext'].'|'.$this->unversioned(pathinfo($f['name'], PATHINFO_FILENAME)));

        return $sets->flatMap(function (Collection $set): Collection {
            if ($set->count() === 1) {
                return $set;
            }
            $marks = $set->map(fn (array $f) => $this->versionMark(pathinfo($f['name'], PATHINFO_FILENAME)));
            $plain = $marks->contains(fn ($m) => $m['kind'] === 'none');
            $onlyNumbered = $marks->every(fn ($m) => $m['kind'] === 'copy-number');
            if ($onlyNumbered && ! $plain) {
                return $set;   // a series, not versions
            }

            $newest = $set->sortByDesc(fn (array $f) => [
                $f['changed']?->getTimestamp() ?? 0,
                $this->versionMark(pathinfo($f['name'], PATHINFO_FILENAME))['rank'],
            ])->first();

            return collect([['older' => $set->count() - 1] + $newest]);
        })->values();
    }

    private const VERSION_MARKS = [
        'copy-number' => '/[ _-]*\((\d+)\)$/u',                                     // "logo (2)"
        'v' => '/[ _-]*v(?:er)?\.?\s*(\d+)(?:\.\d+)*$/iu',                          // "logo v2"
        'word' => '/[ _-]*(?:copy|نسخة|final|new|جديد|نهائي|updated|edited)(?:\s*(\d+))?$/iu',
        'year' => '/[ _-]*((?:19|20)\d{2})$/u',                                     // "logo 2024"
        'date' => '/[ _-]*(\d{6}|\d{8})$/u',                                        // "logo_042024"
    ];

    /**
     * The name with its last version mark taken off its end: one mark only, so names that differ
     * before it stay apart ("XmasBCK202301 copy" and "XmasBCK202302 copy" are two backgrounds).
     */
    private function unversioned(string $stem): string
    {
        $s = mb_strtolower(trim($stem));
        foreach (self::VERSION_MARKS as $re) {
            if (preg_match($re, $s)) {
                $base = rtrim((string) preg_replace($re, '', $s), ' _-');

                return $base !== '' ? $base : $s;   // "New" alone is a name, not a mark
            }
        }

        return $s;
    }

    /** @return array{kind: string, rank: int} how new the name says it is (higher is newer) */
    private function versionMark(string $stem): array
    {
        foreach (self::VERSION_MARKS as $kind => $re) {
            if (preg_match($re, $stem, $m)) {
                $n = (int) ($m[1] ?? 1);
                // A date written month-year (042024) compares by year, then month.
                if ($kind === 'date' && strlen($m[1]) === 6) {
                    $n = (int) (substr($m[1], 2).substr($m[1], 0, 2));
                }

                return ['kind' => $kind, 'rank' => $n + 1];
            }
        }

        return ['kind' => 'none', 'rank' => 0];
    }

    /** @return array<string, int> group => file count */
    public function groupCounts(): array
    {
        return $this->all()->countBy('group')->all();
    }

    /** @param  array<int, string>  $paths */
    public function find(array $paths): Collection
    {
        $wanted = array_flip($paths);

        // From everything, so a request still names a file that was replaced by a newer one or taken out.
        return $this->everything()->filter(fn (array $f): bool => isset($wanted[$f['path']]))->values();
    }

    /**
     * Ranked search. Understands group words (شهادة، شعار، ممول…), program
     * names and donor names, so "شعار أكاديمية الأطفال" finds 008 CU logos
     * and not every logo in the pack.
     *
     * @param  Collection<int, Program>|null  $programs
     */
    public function search(string $query, ?string $group = null, ?Collection $programs = null, int $limit = 150): Collection
    {
        $query = trim($query);
        $program = $this->detectProgram($query, $programs);
        $donor = $this->detectDonor($query);
        $words = array_values(array_filter(
            preg_split('/[\s,.،؟?!]+/u', mb_strtolower($query)) ?: [],
            fn (string $w): bool => mb_strlen($w) > 2,
        ));

        $groups = $this->groupsIn($query);

        if (in_array('donors', $groups, true) || $donor) {
            $groups = array_values(array_diff($groups, ['projects']));
            if ($donor && ! in_array('donors', $groups, true) && ! array_intersect($groups, ['plexi', 'rollup'])) {
                $groups[] = 'donors';
            }
        }

        if (in_array('projects', $groups, true) && ! $program && preg_match('/المؤسسة|الهوب|hope|الرجاء/iu', $query)) {
            $program = ['slug' => 'HC', 'code' => '001'];
        }

        $need = $query !== '' ? ($groups ? 5 : 3) : 1;

        return $this->all()
            ->filter(fn (array $f): bool => $group === null || $group === '' || $f['group'] === $group)
            ->map(function (array $f) use ($groups, $program, $donor, $words, $group, $query): array {
                $s = in_array($f['group'], $groups, true) ? 5 : 0;

                if ($program) {
                    $slug = mb_strtolower($program['slug']);
                    $code = $program['code'];
                    $re = '/(^|[^a-z0-9])('.preg_quote($code, '/').'[ _]?'.preg_quote($slug, '/').'|'.preg_quote($slug, '/').')([^a-z0-9]|$)/i';
                    if (preg_match($re, $f['low']) || str_contains($f['low'], '/'.$code.' '.$slug)) {
                        $s += 4;
                    }
                    if ($f['group'] === 'projects' && ! str_contains($f['low'], '/'.$code.' '.$slug.'/')) {
                        $s -= 5;
                    }
                }

                if ($donor) {
                    if (preg_match('/(^|[^a-z0-9])'.preg_quote(mb_strtolower($donor), '/').'([^a-z0-9]|$)/', $f['low'])) {
                        $s += 4;
                    } elseif ($f['group'] === 'donors') {
                        $s -= 5;
                    }
                }

                foreach ($words as $w) {
                    if (str_contains($f['low'], $w)) {
                        $s += 1;
                    }
                }

                // A file name typed (all or part, "001_HC", "001_HC.png"): found, and the exact one first.
                $name = mb_strtolower($f['name']);
                $typed = mb_strtolower($query);
                if (mb_strlen($typed) > 2 && str_contains($name, $typed)) {
                    $s += 10;
                    if ($typed === $name || $typed === mb_strtolower(pathinfo($f['name'], PATHINFO_FILENAME))) {
                        $s += 5;
                    }
                }

                if ($group && $query === '') {
                    $s = 1;
                }

                return $f + ['score' => $s];
            })
            ->filter(fn (array $f): bool => $f['score'] >= $need)
            ->sort(function (array $a, array $b): int {
                return [$b['score'], self::EXT_RANK[$a['ext']] ?? 9, $a['path']]
                    <=> [$a['score'], self::EXT_RANK[$b['ext']] ?? 9, $b['path']];
            })
            ->take($limit)
            ->values();
    }

    /**
     * A few varied suggestions for the request assistant: at most two per
     * folder so one program's twenty logo variants do not fill the list.
     */
    public function suggest(string $query, ?Collection $programs = null, int $total = 6): Collection
    {
        // Only when they ask for something the pack holds (a logo, a template, a consent form…);
        // naming a program alone is not a reason to offer its logos.
        $groups = $this->groupsIn($query);
        if ($this->detectDonor($query)) {
            $groups[] = 'donors';
        }
        if ($groups === []) {
            return collect();
        }

        $perDir = [];

        return $this->search($query, null, $programs, 200)
            ->filter(fn (array $f): bool => in_array($f['group'], $groups, true))
            ->filter(function (array $f) use (&$perDir): bool {
                $perDir[$f['dir']] = ($perDir[$f['dir']] ?? 0) + 1;

                return $perDir[$f['dir']] <= 2;
            })
            ->take($total)
            ->values();
    }

    /**
     * Pack groups whose keywords appear in the text.
     *
     * @return array<int, string>
     */
    public function groupsIn(string $text): array
    {
        $words = array_values(array_filter(
            preg_split('/[\s,.،؟?!]+/u', mb_strtolower(trim($text))) ?: [],
            fn (string $w): bool => mb_strlen($w) > 2,
        ));

        return collect(self::GROUPS)
            ->filter(fn (array $g): bool => collect($words)->contains(
                fn (string $w): bool => collect(explode(' ', $g[2]))->contains(
                    fn (string $k): bool => mb_strlen($k) > 2 && (str_starts_with($k, $w) || str_starts_with($w, $k)),
                ),
            ))
            ->map(fn (array $g): string => $g[0])
            ->values()
            ->all();
    }

    /** @return array{slug: string, code: string}|null */
    public function detectProgram(string $text, ?Collection $programs = null): ?array
    {
        foreach (config('media-requests.aliases.programs', []) as $slug => $pattern) {
            if (preg_match('/'.$pattern.'/iu', $text)) {
                $program = $programs?->first(fn (Program $p): bool => strcasecmp((string) $p->slug, $slug) === 0);

                return ['slug' => $slug, 'code' => $program ? trim((string) $program->code) : $this->fallbackCode($slug)];
            }
        }

        return null;
    }

    public function detectDonor(string $text): ?string
    {
        foreach (self::DONORS as $pattern => $code) {
            if (preg_match($pattern, $text)) {
                return $code;
            }
        }

        return null;
    }

    private function fallbackCode(string $slug): string
    {
        return [
            'HC' => '001', 'MP' => '002', 'RLF' => '003', 'F2F' => '003', 'S2S' => '004', 'DISC' => '005',
            'SZ' => '006', 'HWR' => '007', 'CU' => '008', 'CYG' => '009', 'ESP' => '009', 'LEAP' => '010',
        ][$slug] ?? '';
    }
}
