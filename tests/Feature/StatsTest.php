<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MediaRequests\TeamStats;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The statistics: for the team and the media manager, with deliveries counted early, on the day or late. */
class StatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);   // includes past deliveries
    }

    public function test_deliveries_and_returns_are_counted(): void
    {
        $s = app(TeamStats::class)->for(90);

        $this->assertSame($s['requests']['delivered'], array_sum($s['timing']));
        $this->assertGreaterThan(0, $s['timing']['early']);
        $this->assertGreaterThan(0, $s['timing']['late']);
        $this->assertGreaterThan(0, $s['returned']);
        $this->assertSame(3, $s['team']['members']);
        $this->assertNotEmpty($s['byType']);
    }

    public function test_the_team_sees_it_and_requesters_do_not(): void
    {
        foreach (['maya', 'fadi', 'admin'] as $who) {
            foreach ([30, 90, 365, 0] as $days) {
                $this->actingAs($this->user($who))->get('/media-requests/stats?days='.$days)
                    ->assertOk()->assertSee(__('media.stats.timing'));
            }
        }

        $this->actingAs($this->user('rami'))->get('/media-requests/stats')->assertForbidden();
        $this->actingAs($this->user('lina'))->get('/media-requests/stats')->assertForbidden();

        // A made-up period falls back to 30 days.
        $this->actingAs($this->user('maya'))->get('/media-requests/stats?days=7')->assertOk();
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
