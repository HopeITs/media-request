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

    public ?int $city_id = null;

    public array $programs = [];

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
            'position_title' => ['nullable', 'string', 'max:120'],
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
        $this->reset(['editing', 'employeeId', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar', 'email', 'password', 'role', 'media_role', 'position_title', 'city_id', 'programs']);
        $this->resetErrorBag();
    }
}
