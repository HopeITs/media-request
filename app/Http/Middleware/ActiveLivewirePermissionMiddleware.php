<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveLivewirePermissionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Login, password reset, and registration also use the shared Livewire
        // update endpoint. Only authenticated requests can match protected
        // application mutation rules.
        if (! $user) {
            return $next($request);
        }

        foreach ($request->input('components', []) as $component) {
            $snapshot = json_decode($component['snapshot'] ?? '', true);
            $componentName = data_get($snapshot, 'memo.name');

            if (! is_string($componentName)) {
                continue;
            }

            foreach ($component['calls'] ?? [] as $call) {
                $method = $call['method'] ?? null;
                $permission = config('access-control.livewire', [])[$componentName][$method] ?? null;

                if (is_string($permission)) {
                    abort_unless($user->hasActivePermission($permission), 403);
                }
            }
        }

        return $next($request);
    }
}
