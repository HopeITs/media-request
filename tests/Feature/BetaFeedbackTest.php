<?php

namespace Tests\Feature;

use App\Livewire\Feedback\Index as FeedbackPage;
use App\Livewire\MediaRequests\ProofreaderPanel;
use App\Models\BetaFeedback;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Feedback while the app is being tried out: anyone sends it, only administrators read it. */
class BetaFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_problem_is_reported_with_its_screenshot_and_page(): void
    {
        $rami = $this->user('rami');

        $this->actingAs($rami)->postJson(route('feedback.store'), [
            'kind' => 'report', 'url' => 'http://elsewhere.example/media-requests/board?x=1',
            'note' => 'The board does not scroll.', 'screenshot' => $this->png(), 'viewport' => '1400x900',
        ])->assertOk();

        $f = BetaFeedback::sole();
        $this->assertSame($rami->id, $f->user_id);
        $this->assertSame('/media-requests/board?x=1', $f->url);   // only the path, never another address
        $this->assertSame('The board does not scroll.', $f->note);
        $this->assertNotNull($f->screenshot);
        Storage::disk('local')->assertExists($f->screenshot);

        // A report needs a note; something that is not an image is not kept.
        $this->actingAs($rami)->postJson(route('feedback.store'), ['kind' => 'report', 'url' => '/'])->assertStatus(422);
        $this->actingAs($rami)->postJson(route('feedback.store'), [
            'kind' => 'report', 'url' => '/', 'note' => 'x', 'screenshot' => 'data:image/png;base64,'.base64_encode('<svg/>'),
        ])->assertOk();
        $this->assertNull(BetaFeedback::latest('id')->first()->screenshot);
    }

    public function test_an_action_is_rated_after_it_is_done(): void
    {
        $rami = $this->user('rami');

        // The action asks for it in the browser.
        Livewire::actingAs($rami)->test(ProofreaderPanel::class)
            ->set('text', 'A short text to check.')->call('check')
            ->assertDispatched('mr-rate', action: 'proofread');

        $this->actingAs($rami)->postJson(route('feedback.store'), [
            'kind' => 'rating', 'rating' => 1, 'action' => 'proofread', 'url' => '/media-requests/home', 'note' => 'Too slow.',
        ])->assertOk();
        $this->actingAs($rami)->postJson(route('feedback.store'), ['kind' => 'rating', 'url' => '/'])->assertStatus(422);   // a rating needs one
        $this->actingAs($rami)->postJson(route('feedback.store'), ['kind' => 'rating', 'rating' => 9, 'url' => '/'])->assertStatus(422);

        $this->assertSame([1, 'proofread', 'Too slow.'], [BetaFeedback::sole()->rating, BetaFeedback::sole()->action, BetaFeedback::sole()->note]);
    }

    public function test_only_administrators_read_the_feedback(): void
    {
        $this->actingAs($this->user('rami'))->postJson(route('feedback.store'), [
            'kind' => 'report', 'url' => '/', 'note' => 'Hello', 'screenshot' => $this->png(),
        ])->assertOk();
        $f = BetaFeedback::sole();

        $this->actingAs($this->user('rami'))->get(route('feedback.index'))->assertForbidden();
        $this->actingAs($this->user('rami'))->get(route('feedback.shot', $f))->assertForbidden();

        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('feedback.index'))->assertOk()->assertSee('Hello');
        $this->actingAs($admin)->get(route('feedback.shot', $f))->assertOk();
        Livewire::actingAs($admin)->test(FeedbackPage::class)->call('toggleDone', $f->id);
        $this->assertNotNull($f->fresh()->done_at);
    }

    /** Whoever works on the app reads what needs a look, then marks it handled with what changed. */
    public function test_the_feedback_is_read_and_closed_from_the_command_line(): void
    {
        $this->actingAs($this->user('rami'))->postJson(route('feedback.store'), ['kind' => 'report', 'url' => '/media-requests/board', 'note' => 'The board does not scroll.'])->assertOk();
        $this->actingAs($this->user('rami'))->postJson(route('feedback.store'), ['kind' => 'rating', 'rating' => 3, 'action' => 'claim', 'url' => '/'])->assertOk();
        $report = BetaFeedback::where('kind', 'report')->sole();

        // A good rating with nothing said needs no look; the report does.
        $this->artisan('media:feedback')->expectsOutputToContain('The board does not scroll.')->expectsOutputToContain('/media-requests/board')->assertSuccessful();

        $this->artisan('media:feedback', ['--done' => [$report->id], '--note' => 'Fixed the scrolling.'])->assertSuccessful();
        $this->assertNotNull($report->fresh()->done_at);
        $this->assertSame('Fixed the scrolling.', $report->fresh()->fix);
        $this->artisan('media:feedback')->expectsOutput('Nothing needs a look.')->assertSuccessful();
    }

    public function test_it_can_be_switched_off(): void
    {
        config(['media-requests.feedback' => false]);

        $this->actingAs($this->user('rami'))->postJson(route('feedback.store'), ['kind' => 'report', 'url' => '/', 'note' => 'x'])->assertNotFound();
        $this->actingAs($this->user('maya'))->get(route('media-requests.index'))->assertOk()->assertDontSee('id="mr-fbk"', false);
        $this->assertSame(0, BetaFeedback::count());
    }

    /** A real one-pixel PNG, as the browser sends it. */
    private function png(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
