<?php

namespace App\Support\MediaRequests;

use App\Models\City;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The Media team list, kept on the server only (it holds people's emails, so never in git):
 * storage/app/private/media-team.csv, or config media-requests.team_file.
 *
 *   email,name_en,name_ar,city,media_role,position_title,work_hours
 *
 * Someone on the list gets their place on the team (role, specialties, city, hours) as
 * soon as they are added, quick-added, or sign in for the first time, and media:team
 * creates every account at once.
 */
class TeamRoster
{
    public const COLUMNS = ['email', 'name_en', 'name_ar', 'city', 'media_role', 'position_title', 'work_hours'];

    /** @var array<string, array<string, string>>|null */
    private ?array $rows = null;

    public function path(): string
    {
        return (string) config('media-requests.team_file', storage_path('app/private/media-team.csv'));
    }

    /**
     * Every row, by email (lower case). An unreadable or missing file is an empty list.
     *
     * @return array<string, array<string, string>>
     */
    public function rows(?string $path = null): array
    {
        if ($path === null && $this->rows !== null) {
            return $this->rows;
        }

        $file = $path ?? $this->path();
        $rows = [];
        if (is_file($file) && ($handle = fopen($file, 'r')) !== false) {
            $head = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($handle, escape: '\\') ?: []);
            while (($line = fgetcsv($handle, escape: '\\')) !== false) {
                if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $row = array_combine($head, array_pad(array_slice(array_map(fn ($v) => trim((string) $v), $line), 0, count($head)), count($head), ''));
                $rows[strtolower($row['email'] ?? '')] = $row + array_fill_keys(self::COLUMNS, '');
            }
            fclose($handle);
        }

        return $path === null ? $this->rows = $rows : $rows;
    }

    /** @return array<string, string>|null */
    public function find(?string $email): ?array
    {
        return $email ? ($this->rows()[strtolower(trim($email))] ?? null) : null;
    }

    /** What is wrong with a row, or null. */
    public function problem(array $row): ?string
    {
        $hours = $row['work_hours'] ?? '';
        $city = strtoupper($row['city'] ?? '');

        return match (true) {
            ! filter_var($row['email'] ?? '', FILTER_VALIDATE_EMAIL) => 'bad email',
            $city !== '' && ! City::where('slug', $city)->exists() => "unknown city {$city}",
            ! in_array($row['media_role'] ?? '', ['member', 'manager'], true) => 'media_role must be member or manager, not '.($row['media_role'] ?? ''),
            $hours !== '' && ! preg_match('/^\d{1,2}(:\d{2})?-\d{1,2}(:\d{2})?$/', $hours) => "work_hours like 10:00-14:00, not {$hours}",
            default => null,
        };
    }

    /** The English name: from the row, or made from the email (george.tello → George Tello). */
    public function nameOf(array $row): string
    {
        return ($row['name_en'] ?? '') ?: Str::of(Str::before((string) $row['email'], '@'))->replace(['.', '_'], ' ')->title()->toString();
    }

    /**
     * Put the person on the team as the row says. Their account (password, sign-in role) is
     * left as it is; a new person gets an account with the returned password.
     *
     * @return string|null the password of a new account
     */
    public function apply(array $row, ?User $user = null): ?string
    {
        $email = strtolower((string) $row['email']);
        $user ??= User::where('email', $email)->first();
        $name = $this->nameOf($row);
        $city = strtoupper((string) $row['city']);

        $fields = [
            'first_name_en' => Str::before($name, ' ') ?: $name,
            'last_name_en' => Str::contains($name, ' ') ? Str::after($name, ' ') : null,
            'city_id' => $city !== '' ? City::where('slug', $city)->value('id') : null,
            'media_role' => $row['media_role'] ?: 'member',
            'position_title' => $row['position_title'] ?: null,
            'work_hours' => $row['work_hours'] ?: null,
            'is_active' => true,
        ];
        if (($row['name_ar'] ?? '') !== '') {
            $fields['first_name_ar'] = Str::before($row['name_ar'], ' ') ?: $row['name_ar'];
            $fields['last_name_ar'] = Str::contains($row['name_ar'], ' ') ? Str::after($row['name_ar'], ' ') : null;
        }

        $password = null;
        if ($user?->profile) {
            $user->profile->update($fields);
        } else {
            $employee = Employee::create($fields + ['username' => Str::before($email, '@'), 'first_name_ar' => $fields['first_name_en']]);
            if ($user) {
                $user->update(['employee_id' => $employee->id]);
            } else {
                $password = Str::password(12, symbols: false);
                User::create(['email' => $email, 'name' => $name, 'password' => $password, 'role' => 'employee', 'employee_id' => $employee->id]);
            }
        }
        app(MediaTeam::class)->flush();

        return $password;
    }

    /** On sign-in or when added: someone on the list who is not on the team yet takes their place. */
    public function welcome(User $user): void
    {
        $row = $this->find($user->email);
        if ($row !== null && $this->problem($row) === null && ! $user->profile?->media_role) {
            $this->apply($row, $user);
        }
    }
}
