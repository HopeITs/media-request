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

    public function test_notifications_are_one_row_per_request_and_open_it(): void
    {
        $rami = $this->user('rami');
        $rami->notifications()->delete();
        // Notifications are faked in these tests, so store four updates about the same request directly.
        $data = (new \App\Notifications\MediaRequestUpdated($this->request, 'comment'))->toArray($rami);
        foreach (range(1, 4) as $i) {
            $rami->notifications()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => \App\Notifications\MediaRequestUpdated::class, 'data' => $data, 'created_at' => now()->subMinutes($i)]);
        }

        $page = \Livewire\Livewire::actingAs($rami)->test(\App\Livewire\MediaRequests\Dashboard::class)->set('bellOpen', true);
        $page->assertSee(trans_choice('media.bell.moreUpdates', 3, ['n' => 3]))   // one row: the latest and "3 more"
            ->assertSee(__('media.bell.unread'));

        $page->call('openRequestNotes', $this->request->id)
            ->assertSet('open', $this->request->id)
            ->assertSet('bellOpen', false);
        $this->assertSame(0, $rami->unreadNotifications()->count());
    }

    public function test_a_calendar_day_offers_a_new_request_and_shows_its_requests(): void
    {
        $day = $this->request->needed_by->toDateString();

        // Rami sees his own request on that day, and can ask for something new on it.
        \Livewire\Livewire::actingAs($this->user('rami'))->test(\App\Livewire\MediaRequests\Dashboard::class)
            ->call('pickDay', $day)
            ->assertSee(__('media.cal.newOnDay'))
            ->assertSee('Flyer');

        // Nour cannot see Rami's request.
        \Livewire\Livewire::actingAs($this->user('nour'))->test(\App\Livewire\MediaRequests\Dashboard::class)
            ->call('pickDay', $day)
            ->assertDontSee('Flyer');

        // The form opened from the day has that day as the needed-by date.
        \Livewire\Livewire::actingAs($this->user('rami'))->test(\App\Livewire\MediaRequests\RequestWizard::class)
            ->call('open', null, null, $day)
            ->assertSet('needed_by', $day);
    }

    public function test_a_day_from_the_calendar_is_not_asked_again(): void
    {
        $day = today()->addWeekdays(6)->toDateString();
        $wizard = \Livewire\Livewire::actingAs($this->user('rami'))->test(\App\Livewire\MediaRequests\RequestWizard::class)
            ->call('open', null, null, $day);

        // A shoot: the day is the event date, shown, not asked; photos are due the turnaround after it.
        $wizard->call('chooseType', '002', 'shoot')
            ->assertSet('answers.eventDate', $day)
            ->assertSet('needed_by', \Illuminate\Support\Carbon::parse($day)->addWeekdays(2)->toDateString())
            ->set('step', 2)
            ->assertSee(__('media.wz.fromCalendar'));

        // A design: the day is the needed-by date.
        $wizard->call('chooseType', '001', 'new')->assertSet('needed_by', $day);
    }

    public function test_decisions_waiting_on_you_are_cards_at_the_top(): void
    {
        // Lina manages DISC: a new DISC request waits for her approval.
        $r = app(MediaRequestWorkflow::class)->submit($this->user('rami'), [
            'service' => '001', 'type' => 'edit', 'program_id' => Program::where('slug', 'DISC')->value('id'),
            'city_id' => City::where('slug', 'ALP')->value('id'), 'title' => 'Badge', 'needed_by' => today()->addWeekdays(8)->toDateString(),
            'answers' => ['editWhat' => ['names']],
        ]);

        $this->actingAs($this->user('lina'))->get('/media-requests/home')
            ->assertOk()
            ->assertSee(trans_choice('media.decide.title', 1, ['n' => 1]))
            ->assertSee(__('media.decide.pm'))
            ->assertSee('Badge');

        // Rami has nothing to decide on it.
        $this->actingAs($this->user('rami'))->get('/media-requests/home')->assertDontSee(__('media.decide.pm'));
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->first()
            ?? ($key === 'nour' ? $this->outsider() : throw new \RuntimeException($key));
    }

    /** A requester with nothing to do with Rami's request. */
    private function outsider(): User
    {
        $e = Employee::create(['username' => 'nour', 'first_name_en' => 'Nour', 'first_name_ar' => 'نور', 'city_id' => City::where('slug', 'HMS')->value('id')]);

        return User::create(['email' => 'nour@example.test', 'name' => 'Nour', 'password' => 'password', 'role' => 'employee', 'employee_id' => $e->id]);
    }
}
