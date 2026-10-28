<?php

namespace App\Providers;

use App\Http\Middleware\ActiveLivewirePermissionMiddleware;
use App\Models\MediaRequest;
use App\Policies\MediaRequestPolicy;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(MediaTeam::class);

        // Request files on the disk in program / governorate / request folders (see MediaFolders).
        config(['media-library.path_generator' => \App\Support\MediaRequests\MediaFolders::class]);
    }

    public function boot(): void
    {
        Gate::policy(MediaRequest::class, MediaRequestPolicy::class);

        Blade::if('activepermission', function (string $permission) {
            return auth()->user()?->hasActivePermission($permission) ?? false;
        });

        // Same guard as the HR app: every Livewire call listed in
        // config('access-control.livewire') needs that permission.
        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', 'locale', ActiveLivewirePermissionMiddleware::class]));
    }
}
