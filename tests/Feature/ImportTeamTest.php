<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\People\Index as People;
use App\Models\Employee;
use App\Models\User;
use App\Support\MediaRequests\MediaTeam;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The Media team list (kept on the server): media:team loads it, and people on it take their place when added or signed in. */
class ImportTeamTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->file = tempnam(sys_get_temp_dir(), 'team');
        file_put_contents($this->file, "email,name_en,name_ar,city,media_role,position_title,work_hours\n"
            ."new.person@example.test,New Person,,LAT,member,\"Videographer / Editor / Photographer (on need)\",09:00-13:00\n"
            ."maya@example.test,Maya,,HMS,manager,\"Communication manager / Photographer\",\n"
            ."rami@example.test,,,DMS,member,\"Photographer\",10:00-14:00\n"
            ."later.one@example.test,,,HMS,member,\"Designer\",\n");
        config(['media-requests.team_file' => $this->file]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_the_team_is_created_and_updated_from_the_list(): void
    {
        $this->artisan('media:team')->assertSuccessful()->expectsOutputToContain('New accounts');

        $new = User::where('email', 'new.person@example.test')->firstOrFail();
        $this->assertSame('LAT', $new->profile->city->slug);
        $this->assertSame('09:00-13:00', $new->profile->work_hours);
        $this->assertSame(['video', 'montage'], app(MediaTeam::class)->skillsOf($new->profile));
        $this->assertTrue(app(MediaTeam::class)->isMember($new->profile));

        // Someone already there keeps their account and role; their team details change.
        $maya = User::where('email', 'maya@example.test')->firstOrFail();
        $this->assertSame('manager', $maya->role);
        $this->assertSame('HMS', $maya->profile->city->slug);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $maya->password));

        file_put_contents($this->file, "email,name_en,name_ar,city,media_role,position_title,work_hours\nbad,X,,ZZZ,member,,\n");
        $this->artisan('media:team')->assertFailed();
    }

    public function test_someone_on_the_list_is_on_the_team_as_soon_as_they_sign_in(): void
    {
        $rami = User::where('email', 'rami@example.test')->firstOrFail();
        $this->assertFalse(app(MediaTeam::class)->isMember($rami->profile));

        Livewire::test(Login::class)->set('email', 'rami@example.test')->set('password', 'password')->call('login');

        $rami = $rami->fresh();
        $this->assertTrue(app(MediaTeam::class)->isMember($rami->profile));
        $this->assertSame('DMS', $rami->profile->city->slug);
        $this->assertSame('10:00-14:00', $rami->profile->work_hours);
    }

    public function test_adding_someone_on_the_list_gives_them_their_place(): void
    {
        $admin = User::where('email', 'admin@example.test')->firstOrFail();

        // Added as an ordinary requester: the list still makes them a designer in Homs.
        Livewire::actingAs($admin)->test(People::class)
            ->set('quickEmail', 'later.one@example.test')->set('quickName', 'Later')->set('quickRole', 'staff')
            ->call('quickAdd')->assertHasNoErrors();

        $e = Employee::where('username', 'later.one')->firstOrFail();
        $this->assertSame('member', $e->media_role);
        $this->assertSame('HMS', $e->city->slug);
        $this->assertSame(['design'], app(MediaTeam::class)->skillsOf($e));
    }
}
