<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MediaRequests\MediaTeam;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** php artisan media:team loads the team from a CSV kept on the server. */
class ImportTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_team_is_created_and_updated_from_a_csv(): void
    {
        $this->seed(DatabaseSeeder::class);
        $file = tempnam(sys_get_temp_dir(), 'team');
        file_put_contents($file, "email,name_en,name_ar,city,media_role,position_title,work_hours\n"
            ."new.person@example.test,New Person,,LAT,member,\"Videographer / Editor / Photographer (on need)\",09:00-13:00\n"
            ."maya@example.test,Maya,,HMS,manager,\"Communication manager / Photographer\",\n");

        $this->artisan('media:team', ['file' => $file])->assertSuccessful()->expectsOutputToContain('New accounts');

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

        file_put_contents($file, "email,name_en,name_ar,city,media_role,position_title,work_hours\nbad,X,,ZZZ,member,,\n");
        $this->artisan('media:team', ['file' => $file])->assertFailed();
        @unlink($file);
    }
}
