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

    public function test_the_main_administrator_can_also_be_the_media_manager(): void
    {
        $this->artisan('media:admin', ['--email' => 'fares.badawi@hcsyria.org', '--name' => 'Fares Badawi', '--name-ar' => 'فارس', '--media-manager' => true])
            ->expectsQuestion('Password (at least 10 characters)', 'a-long-secret')
            ->expectsQuestion('Password again', 'a-long-secret')
            ->assertSuccessful();

        $fares = User::where('email', 'fares.badawi@hcsyria.org')->sole();
        $team = app(\App\Support\MediaRequests\MediaTeam::class);
        $team->flush();
        $this->assertSame('super-admin', $fares->role);
        $this->assertTrue($team->canActAsMediaManager($fares));
        $this->assertTrue($team->isManager($fares->profile));
    }

    public function test_an_administrator_adds_people_to_try_the_app(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@example.test')->sole();
        $disc = Program::where('slug', 'DISC')->value('id');
        $team = app(\App\Support\MediaRequests\MediaTeam::class);

        $people = \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\People\Index::class);

        $people->set('quickRole', 'media')->set('quickEmail', 'photo@hcsyria.org')->set('quickName', 'Photo')->set('quickSpecialty', 'Photographer')
            ->call('quickAdd')->assertHasNoErrors()->assertSet('madeEmail', 'photo@hcsyria.org');
        $password = $people->get('madePassword');
        $this->assertSame(12, strlen($password));
        $photo = User::where('email', 'photo@hcsyria.org')->sole();
        $this->assertTrue(Hash::check($password, $photo->password));
        $team->flush();
        $this->assertTrue($team->isMember($photo->profile));
        $this->assertSame(['photo'], $team->skillsOf($photo->profile));

        $people->set('quickRole', 'pm')->set('quickEmail', 'pm@hcsyria.org')->set('quickName', 'PM')->set('quickPrograms', [$disc])
            ->call('quickAdd')->assertHasNoErrors();
        $team->flush();
        $this->assertSame([$disc], $team->managedProgramIds(User::where('email', 'pm@hcsyria.org')->sole()->profile));

        $people->set('quickRole', 'staff')->set('quickEmail', 'staff@hcsyria.org')->set('quickName', 'Staff')->call('quickAdd')->assertHasNoErrors();
        $this->assertTrue($team->isRequesterOnly(User::where('email', 'staff@hcsyria.org')->sole()));

        // A program manager needs programs; an email can be used once.
        $people->set('quickRole', 'pm')->set('quickEmail', 'pm2@hcsyria.org')->set('quickName', 'PM2')->set('quickPrograms', [])
            ->call('quickAdd')->assertHasErrors('quickPrograms');
        $people->set('quickRole', 'staff')->set('quickEmail', 'staff@hcsyria.org')->call('quickAdd')->assertHasErrors('quickEmail');

        // Only someone who manages people can do it.
        \Livewire\Livewire::actingAs(User::where('email', 'rami@example.test')->sole())->test(\App\Livewire\People\Index::class)->assertForbidden();
    }
}