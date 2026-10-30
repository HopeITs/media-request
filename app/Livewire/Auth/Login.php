<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = true;

    /**
     * The sign-in page is always in English, whatever language was chosen
     * before. Runs on every request, so validation messages are English too.
     * The app itself keeps the person's language after signing in.
     */
    public function boot(): void
    {
        app()->setLocale('en');
    }

    public function login(): void
    {
        $this->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $key = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        if (! Auth::attemptWhen(['email' => $this->email, 'password' => $this->password], fn (User $user) => $user->maySignIn(), $this->remember)) {
            RateLimiter::hit($key);
            $this->reset('password');   // never sent back to the page

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        // The page goes on by itself, after offering to save the password in the browser.
        $this->dispatch('mr-signed-in', to: session()->pull('url.intended', route('media-requests.home')));
    }

    public function render()
    {
        // The sample accounts are listed only on a local preview, never on a server.
        return view('livewire.auth.login', [
            'demo' => app()->isLocal()
                ? User::query()->with('profile')->orderBy('id')->get()
                : collect(),
        ]);
    }
}
