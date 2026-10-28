<?php

namespace Tests\Feature;

use App\Enums\MediaRequestStatus as S;
use App\Models\City;
use App\Models\MediaRequest;
use App\Models\Program;
use App\Models\User;
use App\Notifications\MediaRequestUpdated;
use App\Services\MediaRequestWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The procedure as the team runs it:
 *   - photography (shoots, photo delivery) and filming end with a link the media manager approves;
 *   - designs and editing go to the requester and the program manager, and either one may approve;
 *   - the program manager may postpone at their approval;
 *   - reminders before the needed-by date, on it, and after it.
 */
class ProcedureTest extends TestCase
{
    use RefreshDatabase;

    private MediaRequestWorkflow $w;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DatabaseSeeder::class);
        $this->w = app(MediaRequestWorkflow::class);
    }

    public function test_photography_and_filming_end_with_the_media_managers_check(): void
    {
        foreach ([
            ['002', 'shoot', ['coverage' => 'event', 'eventDate' => today()->addWeekdays(3)->toDateString(), 'place' => 'Hall', 'children' => 'no']],
            ['002', 'export', ['deliverWhat' => 'select']],
            ['003', 'shoot', ['videoKind' => 'event', 'eventDate' => today()->addWeekdays(3)->toDateString(), 'place' => 'Hall', 'children' => 'no', 'duration' => '60s', 'orientation' => 'vertical']],
        ] as [$service, $type, $answers]) {
            $r = $this->toWork($service, $type, $answers, 'sami');
            $this->assertTrue($r->shortPath());
            $this->assertNotContains('req', $r->steps());

            $this->w->sendResult($this->user('sami'), $r, 'https://drive.google.com/drive/folders/photos');
            $this->assertSame(S::InternalReview, $r->fresh()->status);

            $this->w->approveInternal($this->user('maya'), $r->fresh());
            $done = $r->fresh();
            $this->assertSame(S::Delivered, $done->status, "$service.$type is delivered at the check");
            $this->assertSame('https://drive.google.com/drive/folders/photos', $done->archive_link);
            $this->assertNotNull($done->delivered_at);
        }
    }

    public function test_editing_keeps_the_full_review(): void
    {
        $r = $this->toWork('003', 'export', ['editKind' => 'highlights', 'duration' => '60s', 'orientation' => 'vertical'], 'sami');
        $this->assertFalse($r->shortPath());

        $this->w->sendResult($this->user('sami'), $r, 'https://drive.google.com/drive/folders/cut-1');
        $this->w->approveInternal($this->user('maya'), $r->fresh());
        $this->assertSame(S::RequesterReview, $r->fresh()->status);
    }

    public function test_the_program_manager_may_approve_the_result_or_ask_for_changes(): void
    {
        // Rami asks for DISC; Lina manages DISC.
        $r = $this->toWork('001', 'edit', ['editWhat' => ['text']], 'fadi', 'DISC');
        $this->w->sendResult($this->user('fadi'), $r, 'https://drive.google.com/drive/folders/v1');

        Notification::fake();
        $this->w->approveInternal($this->user('maya'), $r->fresh());
        Notification::assertSentTo([$this->user('rami'), $this->user('lina')], MediaRequestUpdated::class);
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($this->user('lina'))->allows('isOwner', $r->fresh()));

        $this->w->requestChanges($this->user('lina'), $r->fresh(), 'Make the logo bigger.');
        $this->assertSame(S::InProgress, $r->fresh()->status);

        $this->w->sendResult($this->user('fadi'), $r->fresh(), 'https://drive.google.com/drive/folders/v2');
        $this->assertSame(S::RequesterReview, $r->fresh()->status);   // already checked once: straight to review
        $this->w->approveResult($this->user('lina'), $r->fresh());     // no changes: either of them is enough
        $this->assertSame(S::Delivery, $r->fresh()->status);

        // Someone else cannot.
        $other = $this->toWork('001', 'edit', ['editWhat' => ['text']], 'fadi', 'SZ');
        $this->w->sendResult($this->user('fadi'), $other, 'https://drive.google.com/drive/folders/x');
        $this->w->approveInternal($this->user('maya'), $other->fresh());
        $this->expectException(AuthorizationException::class);
        $this->w->approveResult($this->user('lina'), $other->fresh());   // Lina does not manage SZ
    }

    public function test_the_program_manager_may_postpone_and_resume_at_their_approval(): void
    {
        $r = $this->submit('001', 'edit', ['editWhat' => ['text']], 'DISC');
        $this->assertSame(S::ProgramApproval, $r->status);

        $this->w->hold($this->user('lina'), $r, 'Waiting for the budget.');
        $held = $r->fresh();
        $this->assertSame(S::OnHold, $held->status);
        $this->assertSame('program_manager', $held->owner());

        $this->w->resume($this->user('lina'), $held);
        $this->assertSame(S::ProgramApproval, $r->fresh()->status);
    }

    public function test_deadline_reminders_go_out_once_a_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:30', 'Asia/Damascus'));   // a Monday

        $soon = $this->toWork('001', 'edit', ['editWhat' => ['text']], 'fadi', 'DISC', '2026-11-03');
        $today = $this->toWork('001', 'edit', ['editWhat' => ['names']], 'fadi', 'DISC', '2026-11-04');
        $today->forceFill(['needed_by' => '2026-11-02'])->saveQuietly();
        $late = $this->toWork('001', 'edit', ['editWhat' => ['dates']], 'fadi', 'DISC', '2026-11-04');
        $late->forceFill(['needed_by' => '2026-10-29'])->saveQuietly();
        $later = $this->toWork('001', 'edit', ['editWhat' => ['images']], 'fadi', 'DISC', '2026-11-20');

        Notification::fake();
        $this->artisan('media:remind')->assertSuccessful();

        $events = fn (User $u) => Notification::sent($u, MediaRequestUpdated::class)->map(fn ($n) => $n->mediaRequest->id.':'.$n->event)->all();
        $this->assertContains($soon->id.':dueSoon', $events($this->user('fadi')));
        $this->assertContains($today->id.':dueToday', $events($this->user('fadi')));
        $this->assertContains($late->id.':overdue', $events($this->user('fadi')));
        $this->assertContains($late->id.':overdue', $events($this->user('maya')));      // late: the media manager too
        $this->assertNotContains($later->id.':dueSoon', $events($this->user('fadi')));

        // Not twice the same day, and nothing at the weekend.
        Notification::fake();
        $this->artisan('media:remind')->assertSuccessful();
        Notification::assertNothingSent();

        Carbon::setTestNow(Carbon::parse('2026-11-07 08:30', 'Asia/Damascus'));   // Saturday
        $this->artisan('media:remind')->expectsOutputToContain('Not a working day')->assertSuccessful();

        Carbon::setTestNow();
    }

    public function test_late_work_shows_a_strip_to_the_assignee_and_the_media_manager(): void
    {
        $r = $this->toWork('001', 'edit', ['editWhat' => ['text']], 'fadi', 'DISC');
        $r->forceFill(['needed_by' => today()->subWeekdays(2)->toDateString()])->saveQuietly();

        $this->actingAs($this->user('fadi'))->get('/media-requests/home')
            ->assertSee('lateband', false)->assertSee(trans_choice('media.late.mine', 1, ['n' => 1]));
        $this->actingAs($this->user('maya'))->get('/media-requests/home')
            ->assertSee(trans_choice('media.late.team', 1, ['n' => 1]));
        $this->actingAs($this->user('sami'))->get('/media-requests/home')->assertDontSee('lateband', false);   // not his
        $this->actingAs($this->user('rami'))->get('/media-requests/home')->assertDontSee('lateband', false);   // the requester
    }

    public function test_delivering_plays_the_celebration(): void
    {
        $r = $this->toWork('002', 'export', ['deliverWhat' => 'select'], 'sami');
        $this->w->sendResult($this->user('sami'), $r, 'https://drive.google.com/drive/folders/p');

        \Livewire\Livewire::actingAs($this->user('maya'))
            ->test(\App\Livewire\MediaRequests\Show::class, ['mediaRequest' => $r->fresh()])
            ->call('approveInternal')
            ->assertDispatched('media-celebrate');

        // The requester's first look at it plays it once (kept on their device).
        $this->actingAs($this->user('rami'))->get('/media-requests/home?open='.$r->id)->assertSee('mr-cel-'.$r->id, false);
    }

    private function toWork(string $service, string $type, array $answers, string $who, string $program = 'SZ', ?string $date = null): MediaRequest
    {
        $r = $this->submit($service, $type, $answers, $program, $date);
        if ($r->status === S::ProgramApproval) {
            $this->w->approveProgram($this->user('lina'), $r);
        }
        $this->w->approveMedia($this->user('maya'), $r->fresh(), 'normal', null, $this->user($who)->employee_id);
        $this->w->start($this->user($who), $r->fresh());

        return $r->fresh();
    }

    private function submit(string $service, string $type, array $answers, string $program = 'SZ', ?string $date = null): MediaRequest
    {
        return $this->w->submit($this->user('rami'), [
            'service' => $service, 'type' => $type,
            'program_id' => Program::where('slug', $program)->value('id'),
            'city_id' => City::where('slug', 'ALP')->value('id'),
            'title' => 'Procedure', 'needed_by' => $date ?? today()->addWeekdays(10)->toDateString(),
            'rush_reason' => 'Test', 'answers' => $answers,
        ]);
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
