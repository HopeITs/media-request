<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\City;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Opens every screen as the people who use it. Catches a page that no longer
 * renders (a Blade error, a missing translation key, a broken relation) that
 * the workflow tests would not notice.
 */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_sign_in_page_is_always_in_english(): void
    {
        foreach (['ar', 'en'] as $chosen) {
            $this->withSession(['locale' => $chosen])->get('/login')
                ->assertOk()
                ->assertSee('<html lang="en" dir="ltr">', false)
                ->assertSee('Sign in')
                ->assertDontSee(__('media.login.btn', [], 'ar'));
        }

        // The sample accounts are listed on a local preview only, never elsewhere.
        $this->get('/login')->assertDontSee('rami@example.test');
    }

    public function test_every_screen_renders_for_the_people_who_use_it(): void
    {
        $request = app(MediaRequestWorkflow::class)->submit($this->user('rami'), [
            'service' => '001', 'type' => 'new',
            'program_id' => Program::where('slug', 'DISC')->value('id'),
            'city_id' => City::where('slug', 'ALP')->value('id'),
            'title' => 'Volunteers', 'needed_by' => today()->addWeekdays(8)->toDateString(),
            'answers' => ['format' => 'cert', 'certNames' => 'blank', 'langs' => ['ar'], 'alsoPrint' => 'no'],
        ]);

        $pages = [
            'rami' => ['/media-requests/hc-pack', '/media-requests/home?open='.$request->id],
            'lina' => ['/media-requests', '/media-requests/approvals', '/media-requests/'.$request->id],
            'maya' => ['/media-requests', '/media-requests/board', '/media-requests/log', '/media-requests/'.$request->id],
            'fadi' => ['/media-requests', '/media-requests/board', '/media-requests/'.$request->id],
            'admin' => ['/media-requests', '/media-requests/board', '/media-requests/log', '/media-requests/approvals', '/people'],
        ];

        foreach ($pages as $who => $urls) {
            foreach ($urls as $url) {
                foreach (['ar', 'en'] as $locale) {
                    $this->actingAs($this->user($who))->withSession(['locale' => $locale])
                        ->get($url)
                        ->assertOk();
                }
            }
        }
    }

    public function test_people_who_only_ask_for_work_stay_on_the_home_screen(): void
    {
        $request = app(MediaRequestWorkflow::class)->submit($this->user('rami'), [
            'service' => '001', 'type' => 'new',
            'program_id' => Program::where('slug', 'DISC')->value('id'),
            'city_id' => City::where('slug', 'ALP')->value('id'),
            'title' => 'Volunteers', 'needed_by' => today()->addWeekdays(8)->toDateString(),
            'answers' => ['format' => 'cert', 'certNames' => 'blank', 'langs' => ['ar'], 'alsoPrint' => 'no'],
        ]);
        $rami = $this->user('rami');

        $this->actingAs($rami)->get('/media-requests')->assertRedirect('/media-requests/home');
        $this->actingAs($rami)->get('/notifications')->assertRedirect('/media-requests/home');
        $this->actingAs($rami)->get('/media-requests/'.$request->id)->assertRedirect('/media-requests/home?open='.$request->id);

        // The request opens in the panel; their requests and notifications are cards; no page links.
        $this->actingAs($rami)->get('/media-requests/home?open='.$request->id)
            ->assertOk()
            ->assertSee('reqpanel', false)
            ->assertSee($request->reference)
            ->assertSee(__('media.dash.card.mine'))
            ->assertDontSee('href="'.route('media-requests.index').'"', false)
            ->assertDontSee('href="'.route('notifications.index').'"', false);

        // The team still has its pages.
        $this->actingAs($this->user('maya'))->get('/media-requests/'.$request->id)->assertOk();
    }

    public function test_people_outside_a_screen_are_kept_out(): void
    {
        $this->actingAs($this->user('rami'))->get('/people')->assertForbidden();
        $this->actingAs($this->user('rami'))->get('/media-requests/board')->assertForbidden();
        $this->actingAs($this->user('rami'))->get('/media-requests/log')->assertForbidden();
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
