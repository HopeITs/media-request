<?php

namespace Tests\Feature;

use App\Enums\MediaRequestStatus as S;
use App\Models\City;
use App\Models\Employee;
use App\Models\MediaRequest;
use App\Models\Program;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The team sees who is working on what; the requester and their program manager see who has their request. */
class TeamStatusTest extends TestCase
{
    use RefreshDatabase;

    private MediaRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DatabaseSeeder::class);

        $this->request = app(MediaRequestWorkflow::class)->submit($this->user('rami'), [
            'service' => '001', 'type' => 'edit',
            'program_id' => Program::where('slug', 'DISC')->value('id'),
            'city_id' => City::where('slug', 'ALP')->value('id'),
            'title' => 'Flyer', 'needed_by' => today()->addWeekdays(8)->toDateString(),
            'answers' => ['editWhat' => ['text']],
        ]);
        $this->request->forceFill([
            'status' => S::InProgress,
            'assignee_id' => Employee::where('username', 'fadi')->value('id'),
            'status_changed_at' => now(),
        ])->save();
    }

    public function test_the_team_card_shows_what_each_person_is_working_on(): void
    {
        foreach (['maya', 'sami'] as $who) {   // the media manager and a team member
            $this->actingAs($this->user($who))->get('/media-requests/home')
                ->assertOk()
                ->assertSee(__('media.crew.now'))
                ->assertSee('Flyer');
        }

        // A requester has no team card.
        $this->actingAs($this->user('rami'))->get('/media-requests/home')->assertDontSee(__('media.crew.now'));
    }

    public function test_the_requester_and_program_manager_see_who_has_it(): void
    {
        $working = __('media.progress.working', ['name' => 'Fadi']);

        $this->actingAs($this->user('rami'))->get('/media-requests/home?open='.$this->request->id)->assertSee($working);
        $this->actingAs($this->user('lina'))->get('/media-requests/'.$this->request->id)->assertSee($working);   // DISC's program manager

        // Waiting for the requester: they are told it is up to them.
        $this->request->forceFill(['status' => S::NeedsInfo])->save();
        $this->actingAs($this->user('rami'))->get('/media-requests/home?open='.$this->request->id)
            ->assertSee(__('media.progress.infoYou', ['name' => 'Fadi']));
    }

    public function test_only_the_media_team_is_told_nothing_waits_for_them(): void
    {
        // Rami asks for work: with nothing waiting on him he is offered help, not "nothing is waiting".
        $this->actingAs($this->user('rami'))->get('/media-requests/home')
            ->assertSee(__('media.home.help'))->assertDontSee(__('media.home.clear'));
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
