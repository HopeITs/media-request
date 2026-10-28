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
            'password' => 'hashed',
        ];
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

        return in_array($permission, config('access-control.role_baselines.'.$this->role, []), true);
    }
}
