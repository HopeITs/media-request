<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Models\City;
use App\Models\Employee;
use App\Models\Program;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Standalone only: who uses the app, their role, whether they are on the
 * Media team, and which programs they manage. Inside the HR app all of this
 * comes from the HR data instead, so this page is not carried over.
 */
class Index extends Component
{
    use AuthorizesActivePermissions;

    public bool $editing = false;

    public ?int $employeeId = null;

    public string $first_name_en = '';

    public string $first_name_ar = '';

    public string $last_name_en = '';

    public string $last_name_ar = '';

    public string $email = '';

    public string $password = '';

    public string $role = 'employee';

    public string $media_role = '';

    /** The job title, as in HR ("Communication team member / Photographer"); the Media specialty is read from it. */
    public string $position_title = '';

    /** Part time only: the hours they work, e.g. "10:00-14:00". */
    public string $work_hours = '';

    public ?int $city_id = null;

    public array $programs = [];

    // Quick add, for trying the app: email, name, one of three roles; a password is made and shown once.
    public string $quickEmail = '';

    public string $quickName = '';

    /** media | staff | pm */
    public string $quickRole = 'staff';

    /** For the Media team: Designer, Photographer or Videographer (the specialty comes from it). */
    public string $quickSpecialty = 'Designer';

    public array $quickPrograms = [];

    /** The password just made, shown once. */
    public ?string $madePassword = null;

    public ?string $madeEmail = null;

    public function mount(): void
    {
        $this->authorizeActivePermission('people.manage');
    }

    public function create(): void
    {
        $this->resetForm();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('people.manage');

        $e = Employee::with(['account', 'managedPrograms'])->findOrFail($id);
        $this->fill([
            'employeeId' => $e->id,
            'first_name_en' => (string) $e->first_name_en,
            'first_name_ar' => (string) $e->first_name_ar,
            'last_name_en' => (string) $e->last_name_en,
            'last_name_ar' => (string) $e->last_name_ar,
            'email' => (string) $e->account?->email,
            'password' => '',
            'role' => $e->account?->role ?? 'employee',
            'media_role' => (string) $e->media_role,
            'position_title' => (string) $e->position_title,
            'work_hours' => (string) $e->work_hours,
            'city_id' => $e->city_id,
            'programs' => $e->managedPrograms->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'editing' => true,
        ]);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->authorizeActivePermission('people.manage');

        $userId = $this->employeeId ? Employee::find($this->employeeId)?->account?->id : null;

        $data = $this->validate([
            'first_name_en' => ['required', 'string', 'max:60'],
            'first_name_ar' => ['required', 'string', 'max:60'],
            'last_name_en' => ['nullable', 'string', 'max:60'],
            'last_name_ar' => ['nullable', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$userId ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['employee', 'manager', 'general-manager', 'super-admin'])],
            'media_role' => ['nullable', Rule::in(['member', 'manager'])],
            'position_title' => ['nullable', 'string', 'max:255'],
            'work_hours' => ['nullable', 'regex:/^\d{1,2}(:\d{2})?-\d{1,2}(:\d{2})?$/'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'programs' => ['array'],
            'programs.*' => ['integer', 'exists:programs,id'],
        ]);

        DB::transaction(function () use ($data, $userId): void {
            $employee = Employee::updateOrCreate(['id' => $this->employeeId], [
                'first_name_en' => $data['first_name_en'],
                'first_name_ar' => $data['first_name_ar'],
                'last_name_en' => $data['last_name_en'],
                'last_name_ar' => $data['last_name_ar'],
                'media_role' => $data['media_role'] ?: null,
                'position_title' => $data['position_title'] ?: null,
                'work_hours' => $data['work_hours'] ?: null,
                'city_id' => $data['city_id'],
            ]);
            $employee->managedPrograms()->sync($data['programs']);

            $account = ['email' => $data['email'], 'role' => $data['role'], 'name' => $data['first_name_en'], 'employee_id' => $employee->id];
            if ($data['password']) {
                $account['password'] = $data['password'];
            }

            User::updateOrCreate(['id' => $userId], $account);
        });

        $this->resetForm();
    }

    /**
     * Add someone to try the app with, in one step. Media team: a team member with a specialty.
     * Team: an ordinary staff member who asks for work. Program manager: approves for the chosen programs.
     */
    public function quickAdd(): void
    {
        $this->authorizeActivePermission('people.manage');

        $data = $this->validate([
            'quickEmail' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'quickName' => ['required', 'string', 'max:60'],
            'quickRole' => ['required', Rule::in(['media', 'staff', 'pm'])],
            'quickSpecialty' => ['required_if:quickRole,media', Rule::in(['Designer', 'Photographer', 'Videographer', 'Videographer / Editor', 'Video Editor'])],
            'quickPrograms' => ['required_if:quickRole,pm', 'array'],
            'quickPrograms.*' => ['integer', 'exists:programs,id'],
        ], [], [
            'quickEmail' => __('media.login.email'), 'quickName' => __('media.people.firstEn'),
            'quickPrograms' => __('media.people.manages'),
        ]);

        $password = \Illuminate\Support\Str::password(12, symbols: false);

        DB::transaction(function () use ($data, $password): void {
            $employee = Employee::create([
                'username' => \Illuminate\Support\Str::before($data['quickEmail'], '@'),
                'first_name_en' => $data['quickName'],
                'first_name_ar' => $data['quickName'],
                'media_role' => $data['quickRole'] === 'media' ? 'member' : null,
                'position_title' => $data['quickRole'] === 'media' ? 'Communication team member / '.$data['quickSpecialty'] : null,
            ]);
            if ($data['quickRole'] === 'pm') {
                $employee->managedPrograms()->sync($data['quickPrograms']);
            }

            User::create([
                'email' => $data['quickEmail'], 'name' => $data['quickName'], 'password' => $password,
                'role' => $data['quickRole'] === 'pm' ? 'manager' : 'employee', 'employee_id' => $employee->id,
            ]);
        });
        app(\App\Support\MediaRequests\MediaTeam::class)->flush();

        $this->madeEmail = $data['quickEmail'];
        $this->madePassword = $password;
        $this->reset(['quickEmail', 'quickName', 'quickPrograms']);
    }

    public function forgetPassword(): void
    {
        $this->reset(['madePassword', 'madeEmail']);
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeActivePermission('people.manage');

        $e = Employee::findOrFail($id);
        abort_if($e->id === auth()->user()->employee_id, 422);
        $e->update(['is_active' => ! $e->is_active]);
    }

    public function render()
    {
        return view('livewire.people.index', [
            'people' => Employee::with(['account', 'managedPrograms', 'city'])->orderBy('first_name_en')->get(),
            'allPrograms' => Program::orderBy('code')->get(),
            'cities' => City::orderBy('code')->get(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['editing', 'employeeId', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar', 'email', 'password', 'role', 'media_role', 'position_title', 'work_hours', 'city_id', 'programs']);
        $this->resetErrorBag();
    }
}
