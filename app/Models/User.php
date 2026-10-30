<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Standalone account. It answers the same questions the HR app's User does
 * (profile, myRole, hasActivePermission) so the media module runs unchanged.
 * Here a user has one role, stored on the row, and the permissions per role
 * come from config('access-control.role_baselines').
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'employee_id', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Used the app in the last few minutes. */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes((int) config('media-requests.presence.online_minutes', 5)));
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function myRole(): ?string
    {
        return $this->role;
    }

    public function activeRoleIs(string ...$roles): bool
    {
        return in_array(strtolower((string) $this->role), array_map('strtolower', $roles), true);
    }

    public function hasActivePermission(string $permission): bool
    {
        if ($this->activeRoleIs(...config('access-control.administrator_roles', []))) {
            return true;
        }

        $mediaManager = $this->profile?->is_active && $this->profile->media_role === 'manager';

        // Standalone only: the media manager also looks after the accounts (People), within the limits
        // People\Index sets: no administrator roles, no administrator accounts.
        if ($permission === 'people.manage' && $mediaManager) {
            return true;
        }

        // Being the media manager or a program's manager is set on the person, whatever their account role:
        // the same people the policy lets act must get through this coarse gate (the team list makes everyone 'employee').
        if ($mediaManager && in_array($permission, config('access-control.role_baselines.manager', []), true)) {
            return true;
        }

        if ($permission === 'media-requests.approve' && $this->profile?->managedPrograms()->exists()) {
            return true;
        }

        return in_array($permission, config('access-control.role_baselines.'.$this->role, []), true);
    }
}
