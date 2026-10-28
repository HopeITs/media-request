<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Support\MediaRequests\MediaTeam;
use App\Support\MediaRequests\TeamRoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the Media team from the team list kept on the server only (never in git:
 * it holds people's emails). See TeamRoster for the columns:
 *
 *   email,name_en,name_ar,city,media_role,position_title,work_hours
 *   someone@hcsyria.org,Some One,,HMS,member,"Photographer / Interviewer / Local print",
 *
 * Someone already there (same email) is updated and keeps their password and role.
 * New people get a password, shown once at the end to pass on to them. People on
 * the list who are added later, or sign in, take their place without this command.
 *
 *   php artisan media:team                      (storage/app/private/media-team.csv)
 *   php artisan media:team other.csv --dry-run
 */
class ImportTeam extends Command
{
    protected $signature = 'media:team {file? : The CSV file (default: the team list)} {--dry-run : Only show what would change}';

    protected $description = 'Create or update the Media team from the team list';

    public function handle(TeamRoster $roster, MediaTeam $team): int
    {
        $path = (string) ($this->argument('file') ?: $roster->path());
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $rows = array_values($roster->rows($path));
        foreach ($rows as $i => $row) {
            if ($problem = $roster->problem($row)) {
                $this->error('Row '.($i + 2).": {$problem}");

                return self::FAILURE;
            }
        }

        $table = array_map(fn (array $row) => [
            $row['email'], $roster->nameOf($row), $row['city'] ?: '—', $row['media_role'], $row['work_hours'] ?: 'full time',
            implode(', ', array_merge($team->skillsFromTitle($row['position_title']),
                array_map(fn ($s) => $s.' (on need)', $team->extraSkillsOf(new Employee(['position_title' => $row['position_title']]))))),
            User::where('email', strtolower($row['email']))->exists() ? 'update' : 'new',
        ], $rows);
        $this->table(['Email', 'Name', 'City', 'Role', 'Hours', 'Specialties', ''], $table);

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was saved.');

            return self::SUCCESS;
        }

        $made = [];
        DB::transaction(function () use ($rows, $roster, &$made): void {
            foreach ($rows as $row) {
                if ($password = $roster->apply($row)) {
                    $made[] = [strtolower($row['email']), $password];
                }
            }
        });

        if ($made !== []) {
            $this->warn('New accounts. Pass each password on privately; it is not shown again (it can be changed on the People page):');
            $this->table(['Email', 'Password'], $made);
        }
        $this->info(count($rows).' team members loaded.');

        return self::SUCCESS;
    }
}
