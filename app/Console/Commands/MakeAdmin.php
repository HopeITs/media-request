<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Creates (or updates) an administrator account, for the first sign-in on a
 * server. The password is asked for and never shown; everything else
 * (people, roles, the Media team) is then set on the People page.
 *
 *   php artisan media:admin
 *   php artisan media:admin --email=fares.badawi@hcsyria.org --name="Fares Badawi" --media-manager
 */
class MakeAdmin extends Command
{
    protected $signature = 'media:admin {--email= : Sign-in email} {--name= : Name in English} {--name-ar= : Name in Arabic}
        {--media-manager : Also the media manager (approves, assigns and checks the work of the team)}';

    protected $description = 'Create or update an administrator who can sign in and manage people';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email');
        $name = $this->option('name') ?: $this->ask('Name (English)');
        $nameAr = $this->option('name-ar') ?: $this->ask('Name (Arabic)', $name);
        $password = $this->secret('Password (at least 10 characters)');
        $again = $this->secret('Password again');

        $check = Validator::make(compact('email', 'name', 'password'), [
            'email' => ['required', 'email', 'max:190'],
            'name' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'min:10'],
        ]);
        if ($check->fails() || $password !== $again) {
            foreach ($check->errors()->all() as $error) {
                $this->error($error);
            }
            if ($password !== $again) {
                $this->error('The two passwords do not match.');
            }

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        $employee = $user?->profile ?? Employee::create(['first_name_en' => $name, 'first_name_ar' => $nameAr, 'username' => strstr($email, '@', true)]);

        if ($this->option('media-manager')) {
            $employee->update(['media_role' => 'manager', 'position_title' => $employee->position_title ?: 'Communication manager', 'is_active' => true]);
            app(\App\Support\MediaRequests\MediaTeam::class)->flush();
        }

        User::updateOrCreate(['email' => $email], [
            'name' => $name, 'password' => $password, 'role' => 'super-admin', 'employee_id' => $employee->id,
        ]);

        $this->info(($user ? 'Updated' : 'Created')." administrator {$email}".($this->option('media-manager') ? ' (also the media manager)' : '').'. Sign in, then add people on the People page.');

        return self::SUCCESS;
    }
}
