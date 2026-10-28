<?php

namespace App\Livewire\Concerns;

trait AuthorizesActivePermissions
{
    protected function authorizeActivePermission(string $permission): void
    {
        abort_unless(auth()->user()?->hasActivePermission($permission), 403);
    }
}
