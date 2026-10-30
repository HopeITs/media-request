<?php

namespace App\Livewire\Account;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The signed-in person's own passkeys. Adding or removing one first asks for the
 * password (the passkey routes answer 423 until then), so an unlocked computer
 * left alone cannot give someone a way back in.
 */
class Security extends Component
{
    public string $password = '';

    public function confirmPassword(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        $key = 'confirm-password|'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        if (! Hash::check($this->password, auth()->user()->password)) {
            RateLimiter::hit($key);
            $this->reset('password');

            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        RateLimiter::clear($key);
        $this->reset('password');
        session()->put('auth.password_confirmed_at', time());
    }

    public function render()
    {
        return view('livewire.account.security', [
            'passkeys' => auth()->user()->passkeys()->latest()->get(),
            'confirmed' => time() - (int) session('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800),
        ]);
    }
}
