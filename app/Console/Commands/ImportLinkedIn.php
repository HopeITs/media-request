<?php

namespace App\Console\Commands;

use App\Enums\MediaRequestStatus as S;
use App\Models\City;
use App\Models\Employee;
use App\Models\MediaRequest;
use App\Models\Program;
use App\Support\MediaRequests\MediaNaming;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Brings the old LinkedIn request form ("LinkedIn Request (Responses)", exported as CSV) into
 * the app as LinkedIn posts (service 004), so the history of what was asked and posted is there.
 *
 *   php artisan media:import-linkedin "LinkedIn Request (Responses) - Form responses 1.csv" --dry-run
 *
 * Names written in several ways are joined with the same private map as the sheet import
 * (storage/app/private/sheet-map.json, never in git).
 *
 *   Published (ticked, a published date or a link)   posted, with the link when there is one
 *   Not published, asked in the last 45 days         open, with the media manager
 *   Not published and older                          cancelled, with a note (many were posted and never ticked)
 *
 * Running it again skips rows already brought in (imported_from = "linkedin:<row>").
 */
class ImportLinkedIn extends Command
{
    protected $signature = 'media:import-linkedin {file : The form responses, as CSV} {--map= : Name map (default storage/app/private/sheet-map.json)}
        {--open-days=45 : Unpublished requests newer than this are brought in as open} {--dry-run : Only show what would happen}';

    protected $description = 'Bring the old LinkedIn request form into the app';

    /** The form's columns, by header (trimmed). */
    private const COL = ['time' => 'Timestamp', 'name' => 'Name', 'title' => 'Post/Event Title:', 'project' => 'Post/Event Project:',
        'event' => 'Event Date:', 'text' => 'Post/Event Content:', 'city' => 'Post/Event City:', 'byTeam' => 'Post/Event by Media Team',
        'photos' => 'Upload the post/event Photos', 'checked' => 'Content Checked', 'design' => 'Design', 'published' => 'Published',
        'publishedOn' => 'Published Date', 'link' => 'link to post'];

    /** Teams that photograph, by the city slug the form names. */
    private const TEAMS = ['ALP', 'HMS', 'DMS', 'LAT'];

    private array $map = [];

    /** @var array<string, Employee> */
    private array $people = [];

    public function handle(MediaNaming $naming, MediaTeam $team): int
    {
        $file = (string) $this->argument('file');
        $mapFile = (string) ($this->option('map') ?: storage_path('app/private/sheet-map.json'));
        if (! is_readable($file) || ($handle = fopen($file, 'r')) === false) {
            $this->error("Cannot read {$file}");

            return self::FAILURE;
        }
        $this->map = is_readable($mapFile) ? (json_decode((string) file_get_contents($mapFile), true) ?: []) : [];

        $headers = array_map(fn ($h) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), fgetcsv($handle, escape: '\\') ?: []);
        $rows = [];
        for ($n = 2; ($line = fgetcsv($handle, escape: '\\')) !== false; $n++) {
            $row = [];
            foreach (self::COL as $key => $header) {
                $i = array_search($header, $headers, true);
                $row[$key] = $i === false ? '' : trim((string) ($line[$i] ?? ''));
            }
            if ($row['time'] !== '') {
                $rows[$n] = $row;
            }
        }
        fclose($handle);

        $since = now()->subDays((int) $this->option('open-days'))->startOfDay();
        $count = ['posted' => 0, 'open' => 0, 'oldOpen' => 0, 'dropped' => 0, 'already' => 0];
        $plans = [];
        $unknown = [];

        foreach ($rows as $n => $v) {
            $at = $this->date($v['time']);
            $who = $this->person($v['name'], $v['city']);

            if ($who === null || $at === null) {
                $count['dropped']++;

                continue;
            }
            if (MediaRequest::where('imported_from', 'linkedin:'.$n)->exists()) {
                $count['already']++;

                continue;
            }

            $link = filter_var($v['link'], FILTER_VALIDATE_URL) ? $v['link'] : null;
            $postedOn = $this->date($v['publishedOn']);
            $posted = strtoupper($v['published']) === 'TRUE' || $postedOn !== null || $link !== null;
            $stale = ! $posted && $at->lt($since);
            $count[$posted ? 'posted' : ($stale ? 'oldOpen' : 'open')]++;

            $program = Program::where('code', Str::before($v['project'], ' '))->first();
            if ($program === null) {
                $unknown['program: '.$v['project']] = true;
            }
            $plans[$n] = compact('at', 'who', 'posted', 'stale', 'postedOn', 'link', 'program', 'v');
        }

        $this->table(['Posted', 'Open (recent)', 'Not posted, older: cancelled', 'Unreadable: left out', 'Already in'], [array_values($count)]);
        $names = collect($plans)->map(fn ($p) => $p['who']['en'])->countBy()->sortKeys();
        $this->line('People who asked ('.$names->count().'): '.$names->map(fn ($c, $name) => "{$name} ({$c})")->implode(', '));
        foreach (array_keys($unknown) as $u) {
            $this->warn("Not found, left empty: {$u}");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was saved.');

            return self::SUCCESS;
        }

        $cities = City::pluck('id', 'slug');
        $mm = $team->managers()->first()?->id;

        DB::transaction(function () use ($plans, $naming, $cities, $mm): void {
            foreach (collect($plans)->sortBy(fn ($p) => $p['at']->timestamp) as $n => $p) {
                $v = $p['v'];
                $city = $this->citySlug($v['city']);
                $requester = $this->employee($p['who']['en'], $p['who']['ar'], $city);
                $done = $p['postedOn'] ?? $p['at']->copy();
                $source = match (true) {
                    strtoupper($v['design']) === 'TRUE' => 'design',
                    mb_strtolower($v['byTeam']) === 'no' => 'mine',
                    default => 'team',
                };
                $note = array_filter([
                    'Imported from the LinkedIn request form (row '.$n.').',
                    $p['stale'] ? 'Not marked as posted in the form: brought in as cancelled. If it was posted, the link can be added in a comment.' : null,
                    ! in_array(mb_strtolower($v['byTeam']), ['', 'yes', 'no'], true) ? 'Photos, as written in the form: '.$v['byTeam'] : null,
                    $v['photos'] !== '' ? 'Photos uploaded with the form: '.$v['photos'] : null,
                ]);

                $r = new MediaRequest([
                    'reference' => $naming->nextReference('004', $p['at']),
                    'requester_id' => $requester->id,
                    'program_id' => $p['program']?->id,
                    'city_id' => $cities[$city] ?? null,
                    'service' => '004',
                    'type' => 'post',
                    'item_code' => 'LNKDN',
                    'title' => Str::limit($v['title'] ?: 'LinkedIn post', 117),
                    'description' => implode("\n", $note),
                    'answers' => array_filter([
                        'eventDate' => $this->date($v['event'])?->toDateString(),
                        'postText' => mb_substr($v['text'], 0, 5000),
                        'photoSource' => $source,
                        'photoTeam' => $source === 'team' && in_array($city, self::TEAMS, true) ? $city : null,
                    ]),
                    'needed_by' => $done->toDateString(),
                    'status' => $p['posted'] ? S::Delivered : ($p['stale'] ? S::Cancelled : S::MediaApproval),
                    'status_changed_at' => $p['posted'] ? $done : $p['at'],
                    'needs_program_approval' => false,
                    'media_approver_id' => $p['posted'] ? $mm : null,
                    'media_approved_at' => $p['posted'] ? $p['at'] : null,
                    'text_ok_at' => $p['posted'] || strtoupper($v['checked']) === 'TRUE' ? $done : null,
                    'archive_link' => $p['link'],
                    'delivered_at' => $p['posted'] ? $done : null,
                    'imported_from' => 'linkedin:'.$n,
                ]);
                $r->created_at = $p['at'];
                $r->updated_at = $p['at'];
                $r->save();
            }
        });

        $this->info(count($plans).' LinkedIn requests brought in.');

        return self::SUCCESS;
    }

    /** @return array{en: string, ar: string}|null */
    private function person(string $raw, string $city): ?array
    {
        $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $raw) ?? ''));
        if ($key === '' || in_array($key, $this->map['drop'] ?? [], true)) {
            return null;
        }
        $to = $this->map['people'][$key.'@'.$this->citySlug($city)] ?? $this->map['people'][$key] ?? null;
        [$en, $ar] = array_pad(explode('|', (string) ($to ?? '')), 2, '');
        $en = $en !== '' ? $en : (preg_match('/\p{Arabic}/u', $raw) ? trim($raw) : Str::title(trim($raw)));

        return ['en' => $en, 'ar' => $ar !== '' ? $ar : $en];
    }

    /** The person by English name; someone not in the app yet is added without an account. */
    private function employee(string $en, ?string $ar, ?string $city): Employee
    {
        if (isset($this->people[mb_strtolower($en)])) {
            return $this->people[mb_strtolower($en)];
        }

        return $this->people[mb_strtolower($en)] = Employee::findByName($en) ?? Employee::create([
            'username' => Str::slug($en, '.') ?: 'linkedin.'.Str::random(6),
            'first_name_en' => Str::before($en, ' ') ?: $en,
            'last_name_en' => Str::contains($en, ' ') ? Str::after($en, ' ') : null,
            'first_name_ar' => $ar ? (Str::before($ar, ' ') ?: $ar) : (Str::before($en, ' ') ?: $en),
            'last_name_ar' => $ar && Str::contains($ar, ' ') ? Str::after($ar, ' ') : null,
            'city_id' => $city ? City::where('slug', $city)->value('id') : null,
        ]);
    }

    /** "002 HMS" → HMS; "002HMS" too. */
    private function citySlug(string $raw): string
    {
        return strtoupper((string) preg_replace('/^\d+\s*/', '', trim($raw)));
    }

    /** The form's dates: "17/03/2026 10:26:09" or "1/5/2026" (day first), Damascus time. */
    private function date(string $raw): ?Carbon
    {
        $raw = trim($raw);
        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $raw, $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0), 'Asia/Damascus');
    }
}
