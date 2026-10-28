<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Employee;
use App\Models\User;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads the Media team from a CSV file kept on the server only (never in git:
 * it holds people's emails). One row per person:
 *
 *   email,name_en,name_ar,city,media_role,position_title,work_hours
 *   someone@hcsyria.org,Some One,,HMS,member,"Photographer / Interviewer / Local print",
 *
 * city:        governorate slug (ALP, HMS, DMS, LAT, …)
 * media_role:  member or manager (the media manager and their vice)
 * work_hours:  empty for full time, "10:00-14:00" for part time
 *
 * Someone already there (same email) is updated and keeps their password and role.
 * New people get a password, shown once at the end to pass on to them.
 *
 *   php artisan media:team storage/app/private/media-team.csv
 */
class ImportTeam extends Command
{
    protected $signature = 'media:team {file : The CSV file} {--dry-run : Only show what would change}';

    protected $description = 'Create or update the Media team from a CSV file';

    public function handle(MediaTeam $team): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $head = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($handle, escape: '\\') ?: []);
        $rows = [];
        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) > 0) {
                $rows[] = array_combine($head, array_pad(array_map(fn ($v) => trim((string) $v), $line), count($head), ''));
            }
        }
        fclose($handle);

        $cities = City::pluck('id', 'slug');
        $made = [];
        $table = [];

        foreach ($rows as $i => $row) {
            $email = strtolower($row['email'] ?? '');
            $city = strtoupper($row['city'] ?? '');
            $role = $row['media_role'] ?? 'member';
            $hours = $row['work_hours'] ?? '';
            $problem = match (true) {
                ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'bad email',
                $city !== '' && ! $cities->has($city) => "unknown city {$city}",
                ! in_array($role, ['member', 'manager'], true) => "media_role must be member or manager, not {$role}",
                $hours !== '' && ! preg_match('/^\d{1,2}(:\d{2})?-\d{1,2}(:\d{2})?$/', $hours) => "work_hours like 10:00-14:00, not {$hours}",
                default => null,
            };
            if ($problem) {
                $this->error('Row '.($i + 2).": {$problem}");

                return self::FAILURE;
            }

            $nameEn = ($row['name_en'] ?? '') ?: Str::of(Str::before($email, '@'))->replace('.', ' ')->title()->toString();
            $user = User::where('email', $email)->first();
            $table[] = [$email, $nameEn, $city ?: '—', $role, $hours ?: 'full time', implode(', ', array_merge(
                $team->skillsFromTitle($row['position_title'] ?? ''),
                array_map(fn ($s) => $s.' (on need)', $team->extraSkillsOf(new Employee(['position_title' => $row['position_title'] ?? '']))),
            )), $user ? 'update' : 'new'];

            if ($this->option('dry-run')) {
                continue;
            }

            DB::transaction(function () use ($user, $email, $nameEn, $row, $city, $cities, $role, $hours, &$made): void {
                $fields = [
                    'first_name_en' => Str::before($nameEn, ' ') ?: $nameEn,
                    'last_name_en' => Str::contains($nameEn, ' ') ? Str::after($nameEn, ' ') : null,
                    'city_id' => $city !== '' ? $cities[$city] : null,
                    'media_role' => $role,
                    'position_title' => ($row['position_title'] ?? '') ?: null,
                    'work_hours' => $hours ?: null,
                    'is_active' => true,
                ];
                if (($row['name_ar'] ?? '') !== '') {
                    $fields['first_name_ar'] = Str::before($row['name_ar'], ' ') ?: $row['name_ar'];
                    $fields['last_name_ar'] = Str::contains($row['name_ar'], ' ') ? Str::after($row['name_ar'], ' ') : null;
                }

                if ($user?->profile) {
                    $user->profile->update($fields);

                    return;
                }

                $employee = Employee::create($fields + ['username' => Str::before($email, '@'), 'first_name_ar' => $fields['first_name_en']]);
                if ($user) {
                    $user->update(['employee_id' => $employee->id]);

                    return;
                }
                $password = Str::password(12, symbols: false);
                User::create(['email' => $email, 'name' => $nameEn, 'password' => $password, 'role' => 'employee', 'employee_id' => $employee->id]);
                $made[] = [$email, $password];
            });
        }

        $team->flush();
        $this->table(['Email', 'Name', 'City', 'Role', 'Hours', 'Specialties', ''], $table);

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was saved.');

            return self::SUCCESS;
        }
        if ($made !== []) {
            $this->warn('New accounts. Pass each password on privately; it is not shown again (it can be changed on the People page):');
            $this->table(['Email', 'Password'], $made);
        }
        $this->info(count($rows).' team members loaded.');

        return self::SUCCESS;
    }
}
