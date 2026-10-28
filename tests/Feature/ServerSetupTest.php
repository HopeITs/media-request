<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** On a server: no sample people, and the first administrator comes from media:admin. */
class ServerSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_seeds_places_but_no_sample_people(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();   // as in DEPLOY.md

        $this->assertGreaterThan(0, Program::count());
        $this->assertGreaterThan(0, City::count());
        $this->assertSame(0, User::count());
    }

    public function test_media_admin_creates_an_administrator(): void
    {
        $this->artisan('media:admin', ['--email' => 'it@hope.example', '--name' => 'IT', '--name-ar' => 'المعلوماتية'])
            ->expectsQuestion('Password (at least 10 characters)', 'a-long-secret')
            ->expectsQuestion('Password again', 'a-long-secret')
            ->assertSuccessful();

        $admin = User::where('email', 'it@hope.example')->sole();
        $this->assertSame('super-admin', $admin->role);
        $this->assertTrue(Hash::check('a-long-secret', $admin->password));
        $this->assertNotNull($admin->profile);
    }

    public function test_media_admin_refuses_a_short_or_mismatched_password(): void
    {
        $this->artisan('media:admin', ['--email' => 'it@hope.example', '--name' => 'IT', '--name-ar' => 'IT'])
            ->expectsQuestion('Password (at least 10 characters)', 'short')
            ->expectsQuestion('Password again', 'other')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
