<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Fails when a Livewire component gains a public method that nobody has
 * classified as either a security-sensitive mutation or a safe read.
 *
 * Every public method on a Livewire component is an HTTP endpoint: the browser
 * can call it with arbitrary arguments regardless of which buttons the page
 * renders. ActiveLivewirePermissionMiddleware only checks methods present in
 * config('access-control.livewire') and lets anything else through, so a new
 * action is unprotected by default — and silently so.
 *
 * This test converts that silence into a build failure. It is *not* itself an
 * authorization control: the permission map is coarse, and the real check for
 * ownership and city scope belongs inside the method (a Gate call or a scoped
 * query). What this guarantees is that no action reaches production without
 * somebody having made that decision explicitly.
 *
 * To resolve a failure, add the method to either:
 *   - config('access-control.livewire')[component][method] — it mutates state
 *     and requires a permission; or
 *   - config('access-control.livewire_exempt')[component][] — it is read-only,
 *     UI-only, or self-scoped, with a comment saying which.
 */
class LivewireMutationCoverageTest extends TestCase
{
    /**
     * Framework surface, not application actions. Livewire and its features
     * define these; blocking them would break rendering, file uploads, and
     * pagination.
     */
    private const FRAMEWORK_METHODS = [
        '__construct', '__get', '__set', '__call', '__invoke', '__unset', '__isset',
        'mount', 'boot', 'booted', 'render', 'hydrate', 'dehydrate', 'updating',
        'updated', 'rendering', 'rendered', 'exception',
    ];

    public function test_every_public_livewire_action_is_classified(): void
    {
        $mapped = config('access-control.livewire', []);
        $exempt = config('access-control.livewire_exempt', []);
        $unclassified = [];

        foreach ($this->livewireComponents() as $class) {
            $name = $this->componentName($class);
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($this->isFrameworkSurface($method, $reflection)) {
                    continue;
                }

                $isMapped = isset($mapped[$name][$method->getName()]);
                $isExempt = in_array($method->getName(), $exempt[$name] ?? [], true);

                if (! $isMapped && ! $isExempt) {
                    $unclassified[] = "{$name}::{$method->getName()}";
                }
            }
        }

        sort($unclassified);

        $this->assertSame([], $unclassified, sprintf(
            "%d unclassified public Livewire action(s). Each is callable directly over HTTP.\n".
            "Add every one to config('access-control.livewire') with a permission, or to\n".
            "config('access-control.livewire_exempt') with a comment explaining why it is safe:\n\n  %s\n",
            count($unclassified),
            implode("\n  ", $unclassified),
        ));
    }

    /**
     * Anything inherited from the framework, plus computed-property accessors
     * and Livewire's `updatedFoo` / `updatingFoo` hooks, which the framework
     * invokes rather than the client.
     */
    private function isFrameworkSurface(ReflectionMethod $method, ReflectionClass $class): bool
    {
        $name = $method->getName();

        if (in_array($name, self::FRAMEWORK_METHODS, true)) {
            return true;
        }

        if ($method->isStatic() || $method->isConstructor()) {
            return true;
        }

        // Reflection reports a trait method as declared by the *using* class,
        // so the class name cannot distinguish "this component defines an
        // action" from "this component uses WithPagination". The defining file
        // can: only methods written in app/Livewire itself are application
        // actions. Vendor traits (WithPagination, WithFileUploads) and shared
        // app traits (WithAlerts) are reviewed once at their source instead of
        // once per component that uses them.
        $file = $method->getFileName();

        if ($file === false || ! Str::startsWith(
            str_replace('\\', '/', $file),
            str_replace('\\', '/', app_path('Livewire')),
        )) {
            return true;
        }

        // getFooProperty() — a computed property, evaluated during render.
        if (Str::startsWith($name, 'get') && Str::endsWith($name, 'Property')) {
            return true;
        }

        return false;
    }

    /** @return list<class-string<Component>> */
    private function livewireComponents(): array
    {
        $path = app_path('Livewire');

        if (! is_dir($path)) {
            return [];
        }

        $classes = [];

        foreach (Finder::create()->files()->in($path)->name('*.php') as $file) {
            $class = $this->classFor($file);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Component::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    private function classFor(SplFileInfo $file): string
    {
        $relative = Str::after($file->getRealPath(), app_path().DIRECTORY_SEPARATOR);

        return 'App\\'.str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relative,
        );
    }

    /** The dotted name Livewire uses in the request payload. */
    private function componentName(string $class): string
    {
        $relative = Str::after($class, 'App\\Livewire\\');

        return collect(explode('\\', $relative))
            ->map(fn (string $segment) => Str::kebab($segment))
            ->implode('.');
    }
}
