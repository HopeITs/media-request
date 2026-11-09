<?php

namespace Tests\Feature;

use App\Enums\MediaRequestStatus as S;
use App\Livewire\MediaRequests\Show;
use App\Models\City;
use App\Models\Program;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** A design goes to the review only once the designer ticked what the review sent back most: text, logos, facts, versions. */
class SendChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_design_is_sent_only_with_every_line_ticked(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $w = app(MediaRequestWorkflow::class);
        $fadi = $this->user('fadi');

        $r = $w->submit($this->user('lina'), [
            'service' => '001', 'type' => 'new', 'title' => 'Open day',
            'program_id' => Program::where('slug', 'DISC')->value('id'), 'city_id' => City::where('slug', 'ALP')->value('id'),
            'needed_by' => today()->addWeekdays(8)->toDateString(),
            'answers' => ['format' => 'poster', 'versions' => ['story'], 'langs' => ['ar']],
        ]);
        $w->approveMedia($this->user('maya'), $r, 'normal', null, $fadi->employee_id);
        $w->start($fadi, $r);

        $show = Livewire::actingAs($fadi)->test(Show::class, ['mediaRequest' => $r->fresh()])
            ->assertSee(__('media.check.title'))
            ->assertSee(__('media.check.logo'))
            ->set('link', 'https://drive.example.test/poster')
            ->set('sendChecks', ['text', 'logo'])
            ->call('sendResult')->assertHasErrors('sendChecks');
        $this->assertSame(S::InProgress, $r->fresh()->status);

        $show->set('link', 'https://drive.example.test/poster')->set('sendChecks', ['text', 'logo', 'facts', 'versions'])
            ->call('sendResult')->assertHasNoErrors()->assertSet('sendChecks', []);
        $this->assertSame(S::InternalReview, $r->fresh()->status);
    }

    /** The check of a result waits half a working day at most before the media manager is told, once. */
    public function test_a_slow_review_alerts_the_media_manager_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $calendar = app(\App\Support\MediaRequests\WorkCalendar::class);

        // Working time only: Friday 15:00 to Monday 11:00 is 2 + 2 hours.
        $this->assertSame(240, $calendar->workMinutesBetween(
            \Illuminate\Support\Carbon::parse('2026-11-06 15:00', 'Asia/Damascus'), \Illuminate\Support\Carbon::parse('2026-11-09 11:00', 'Asia/Damascus')));

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09 09:30', 'Asia/Damascus'));
        $r = $this->makeRequest();
        $r->forceFill(['status' => S::InternalReview, 'status_changed_at' => now()])->save();
        $this->assertFalse($r->fresh()->isLate());

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09 12:00', 'Asia/Damascus'));
        $this->artisan('media:review-alert')->assertSuccessful();
        $this->assertNull($r->fresh()->review_alerted_at);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09 14:00', 'Asia/Damascus'));
        $this->assertTrue($r->fresh()->isLate());
        $this->artisan('media:review-alert')->assertSuccessful();
        $this->assertNotNull($r->fresh()->review_alerted_at);
        Notification::assertSentTo($this->user('maya'), \App\Notifications\MediaRequestUpdated::class);

        $before = $r->fresh()->review_alerted_at;
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09 16:00', 'Asia/Damascus'));
        $this->artisan('media:review-alert')->assertSuccessful();
        $this->assertEquals($before, $r->fresh()->review_alerted_at);   // once each time it is sent
    }

    private function makeRequest(): \App\Models\MediaRequest
    {
        return app(MediaRequestWorkflow::class)->submit($this->user('lina'), [
            'service' => '001', 'type' => 'new', 'title' => 'Open day',
            'program_id' => Program::where('slug', 'DISC')->value('id'), 'city_id' => City::where('slug', 'ALP')->value('id'),
            'needed_by' => '2026-11-20',
            'answers' => ['format' => 'poster', 'versions' => ['story'], 'langs' => ['ar']],
        ]);
    }

    private function user(string $who): User
    {
        return User::where('email', $who.'@example.test')->firstOrFail();
    }
}
