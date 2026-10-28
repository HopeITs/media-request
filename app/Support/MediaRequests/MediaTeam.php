<?php

namespace App\Support\MediaRequests;

use App\Models\Employee;
use App\Models\MediaRequest;
use App\Models\Program;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who is who for media requests — STANDALONE version.
 *
 * Same public methods as the HR app's MediaTeam, but read from this app's own
 * tables instead of contracts:
 *
 *  - Media team      employees.media_role is member or manager
 *  - Media manager   employees.media_role = manager
 *  - Program manager a row in program_managers (programs are country-wide)
 *
 * This is the only file that differs from the HR version of the module.
 * Results are memoized for the lifetime of the request.
 */
class MediaTeam
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function isAdministrator(?User $user): bool
    {
        return $user !== null && in_array($user->myRole(), config('access-control.administrator_roles', []), true);
    }

    /** super-admin may stand in for the media manager (setup, absences). */
    public function canActAsMediaManager(?User $user): bool
    {
        return $user !== null && ($user->myRole() === 'super-admin' || $this->isManager($user->profile));
    }

    /**
     * Someone who only asks for work: not on the media team, approves for no
     * program, and manages nobody. They get the home screen only; their
     * requests and notifications open inside it.
     */
    public function isRequesterOnly(?User $user): bool
    {
        return $user !== null
            && ! $this->isMember($user->profile)
            && ! $this->canActAsMediaManager($user)
            && $this->managedProgramIds($user->profile) === []
            && ! $user->hasActivePermission('people.manage');
    }

    /** The field a request belongs to (design, photo, video, montage), from its service and type. */
    public function specialty(?string $service, ?string $type): ?string
    {
        foreach (config('media-requests.specialties', []) as $key => $s) {
            $types = $s['for'][$service] ?? null;
            if ($types === '*' || (is_array($types) && in_array($type, $types, true))) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The member's specialties, read from their position title, the same way
     * the HR app reads them from the HR position.
     *
     * @return array<int, string>
     */
    public function skillsOf(?Employee $employee): array
    {
        return $this->skillsFromTitle((string) $employee?->position_title);
    }

    /**
     * Specialties named in a position title: "Communication team member / Photographer"
     * gives photo; a Videographer films and edits, so gives video and montage.
     *
     * @return array<int, string>
     */
    public function skillsFromTitle(string $title): array
    {
        $title = trim($title);

        return $title === '' ? [] : array_keys(array_filter(
            config('media-requests.specialties', []),
            fn (array $s): bool => isset($s['title']) && preg_match('/'.$s['title'].'/iu', $title) === 1,
        ));
    }

    public function hasSkill(?Employee $employee, ?string $specialty): bool
    {
        return $specialty !== null && in_array($specialty, $this->skillsOf($employee), true);
    }

    /**
     * Team members for a request's field: its specialists first, then everyone else.
     * For a shoot, the team of the place comes first (see coverage).
     *
     * @return Collection<int, Employee>
     */
    public function membersFor(?string $specialty, ?MediaRequest $r = null): Collection
    {
        $area = $r !== null && $this->onSite($r) ? $this->areaTeams($r) : null;

        return $this->members()->sortBy(fn (Employee $e) => [
            $area === null ? 0 : $this->areaRank($e, $area),
            $this->hasSkill($e, $specialty) ? 0 : 1,
        ])->values();
    }

    /** A photo or video shoot: someone has to be there, so the team of the place does it. */
    public function onSite(MediaRequest $r): bool
    {
        return in_array($r->type, config('media-requests.coverage.on_site.'.$r->service, []), true);
    }

    /**
     * The governorates whose team covers the request's place, nearest first.
     *
     * @return array<int, string>
     */
    public function areaTeams(MediaRequest $r): array
    {
        $slug = $r->city?->slug;

        return $slug === null ? [] : config('media-requests.coverage.served_by.'.$slug, [$slug]);
    }

    /** Covered through a service agreement (Al-Jazira), not by our own team. */
    public function underAgreement(MediaRequest $r): bool
    {
        return $this->onSite($r) && in_array($r->city?->slug, config('media-requests.coverage.agreement', []), true);
    }

    /** Whether the member's own team covers the request; anyone does for work that is not a shoot. */
    public function coversArea(?Employee $employee, MediaRequest $r): bool
    {
        return ! $this->onSite($r) || ($employee !== null && $this->areaRank($employee, $this->areaTeams($r)) < 99);
    }

    /** @param  array<int, string>  $area */
    private function areaRank(Employee $e, array $area): int
    {
        $i = array_search($e->city?->slug, $area, true);

        return $i === false ? 99 : $i;
    }

    public function isMember(?Employee $employee): bool
    {
        return $employee !== null && $employee->is_active && in_array($employee->media_role, ['member', 'manager'], true);
    }

    public function isManager(?Employee $employee): bool
    {
        return $employee !== null && $employee->is_active && $employee->media_role === 'manager';
    }

    /** @return array<int, int> */
    public function managedProgramIds(?Employee $employee): array
    {
        if ($employee === null) {
            return [];
        }

        return $this->memo['pm-of:'.$employee->id] ??= $employee->managedPrograms()->pluck('programs.id')->map(fn ($id) => (int) $id)->all();
    }

    public function managesProgram(?Employee $employee, ?int $programId): bool
    {
        return $programId !== null && in_array($programId, $this->managedProgramIds($employee), true);
    }

    /** @return Collection<int, Employee> */
    public function programManagers(?Program $program): Collection
    {
        if ($program === null) {
            return collect();
        }

        return $this->memo['pm:'.$program->id] ??= $program->managers()
            ->where('employees.is_active', true)->with('account')->orderBy('first_name_en')->get();
    }

    /** @return Collection<int, Employee> */
    public function managers(): Collection
    {
        return $this->memo['managers'] ??= Employee::query()
            ->where('is_active', true)->where('media_role', 'manager')->with('account')->orderBy('first_name_en')->get();
    }

    /** @return Collection<int, Employee> */
    public function members(): Collection
    {
        return $this->memo['members'] ??= Employee::query()
            ->where('is_active', true)->whereIn('media_role', ['member', 'manager'])->with(['account', 'city'])->orderBy('first_name_en')->get();
    }

    /** @return Collection<int, User> */
    public function accounts(Collection $employees): Collection
    {
        return $employees
            ->filter(fn (?Employee $e): bool => $e !== null && $e->is_active !== false)
            ->map(fn (Employee $e): ?User => $e->account)
            ->filter()
            ->unique('id')
            ->values();
    }

    public function departmentCode(): string
    {
        return (string) config('media-requests.media_department_code', 'CT');
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
