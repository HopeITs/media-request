<?php

namespace Tests\Feature;

use App\Enums\MediaRequestStatus as S;
use App\Livewire\MediaRequests\Show;
use App\Models\City;
use App\Models\MediaRequest;
use App\Models\Program;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\MediaAssistant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A news post on LinkedIn: the program asks, the media manager approves, the media team prepares
 * the photos while PR checks the text, then the LinkedIn officer posts it and adds the link.
 */
class LinkedInPostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->user('sami')->profile->update(['linkedin_publisher' => true]);
        $this->user('rami')->profile->update(['pr_reviewer' => true]);
    }

    public function test_from_the_news_to_the_link_with_the_text_checked_beside_the_photos(): void
    {
        $w = app(MediaRequestWorkflow::class);
        $r = $w->submit($this->user('lina'), $this->linkedInPost());   // the program's own manager: no program step
        $this->assertSame(S::MediaApproval, $r->status);
        $this->assertSame('LNKDN', $r->item_code);
        $this->assertSame(['submitted', 'mm', 'claim', 'progress', 'text', 'publish'], $r->steps());

        // The officer and PR see it, but nothing is theirs yet.
        $this->assertTrue($this->user('sami')->can('view', $r));
        $this->assertFalse($this->user('rami')->can('reviewText', $r));

        $w->approveMedia($this->user('maya'), $r, 'normal', null, $this->user('fadi')->employee_id);
        $this->assertTrue($this->user('rami')->can('reviewText', $r->fresh()));
        $this->assertTrue($this->user('rami')->can('isOwner', $r->fresh()));   // waits on PR beside the photo work

        // PR fixes the text while the photos are being prepared.
        $w->approveText($this->user('rami'), $r->fresh(), 'Students of DISC visited the factory.');
        $r->refresh();
        $this->assertNotNull($r->text_ok_at);
        $this->assertSame('Students of DISC visited the factory.', $r->answer('finalText'));
        $this->assertSame(S::Assigned, $r->status);

        $w->start($this->user('fadi'), $r);
        $w->sendResult($this->user('fadi'), $r, 'https://drive.example.test/photos');
        $this->assertSame(S::Publishing, $r->status);   // the text was ready: straight to the officer
        $this->assertTrue($this->user('sami')->can('isOwner', $r));
        $this->assertFalse($this->user('lina')->can('publish', $r));

        Livewire::actingAs($this->user('sami'))->test(Show::class, ['mediaRequest' => $r])
            ->assertSee('Students of DISC visited the factory.')
            ->set('link', 'not a link')->call('publish')->assertHasErrors('link')
            ->set('link', 'https://www.linkedin.com/feed/update/urn:li:activity:1')->call('publish')->assertHasNoErrors();

        $r->refresh();
        $this->assertSame(S::Delivered, $r->status);
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:activity:1', $r->archive_link);
        $this->assertSame(4, $r->simpleStage());
    }

    /** Approving is one decision with the best fit chosen; after it, someone else's steps are not put in front of the manager. */
    public function test_the_media_manager_approves_and_assigns_in_one_step(): void
    {
        $w = app(MediaRequestWorkflow::class);
        $r = $w->submit($this->user('lina'), $this->linkedInPost());
        $fadi = $this->user('fadi');   // a designer; the photos of a post are design work

        Livewire::actingAs($this->user('maya'))->test(Show::class, ['mediaRequest' => $r])
            ->assertSet('assigneeId', $fadi->employee_id)
            ->assertSee(__('media.fm.giveTo'))
            ->call('approveMedia')->assertHasNoErrors();

        $r->refresh();
        $this->assertSame([S::Assigned, $fadi->employee_id, 'normal'], [$r->status, $r->assignee_id, $r->priority]);

        Livewire::actingAs($this->user('maya'))->test(Show::class, ['mediaRequest' => $r])
            ->assertDontSee(__('media.act.startBtn'))
            ->assertSee(__('media.act.reassign'));
        Livewire::actingAs($fadi)->test(Show::class, ['mediaRequest' => $r])->assertSee(__('media.act.startBtn'));

        // In the card on the home screen: a message keeps it open, a decision closes it.
        Livewire::actingAs($fadi)->test(Show::class, ['mediaRequest' => $r, 'embedded' => true])
            ->set('body', 'Starting today.')->call('comment')->assertNotDispatched('media-request-decided')
            ->call('start')->assertDispatched('media-request-decided');
    }

    public function test_photos_ready_first_wait_for_the_text(): void
    {
        $w = app(MediaRequestWorkflow::class);
        $r = $w->submit($this->user('lina'), $this->linkedInPost());
        $w->approveMedia($this->user('maya'), $r, 'normal', null, $this->user('fadi')->employee_id);
        $w->start($this->user('fadi'), $r);
        $w->sendResult($this->user('fadi'), $r, 'https://drive.example.test/photos');
        $this->assertSame(S::TextReview, $r->status);
        $this->assertSame('pr', $r->owner());

        $w->approveText($this->user('rami'), $r);   // left as written
        $this->assertSame(S::Publishing, $r->status);
        $this->assertNull($r->answer('finalText'));
    }

    public function test_own_photos_come_with_the_request_and_the_assistant_knows_a_linkedin_post(): void
    {
        $w = app(MediaRequestWorkflow::class);
        $mine = $this->linkedInPost(['photoSource' => 'mine']);
        unset($mine['answers']['photoTeam']);

        $this->expectException(ValidationException::class);
        try {
            $w->submit($this->user('lina'), $mine);
        } finally {
            $this->assertSame(0, MediaRequest::where('service', '004')->count());
            $this->assertSame(1, $w->submit($this->user('lina'), $mine, [UploadedFile::fake()->image('visit.jpg')])->getMedia('attachments')->count());

            $read = app(MediaAssistant::class)->analyze('بدي ننزل خبر عاللينكد ان عن زيارة المعمل مبارح', Program::all(), City::all());
            $this->assertSame(['004', 'post'], [$read['service'], $read['type']]);
            $this->assertSame(today()->subDay()->toDateString(), $read['answers']['eventDate']);
            $this->assertNull($read['needed_by']);
        }
    }

    private function linkedInPost(array $answers = []): array
    {
        return [
            'service' => '004',
            'type' => 'post',
            'program_id' => Program::where('slug', 'DISC')->value('id'),
            'city_id' => City::where('slug', 'ALP')->value('id'),
            'title' => 'Factory visit',
            'needed_by' => today()->addWeekdays(5)->toDateString(),
            'answers' => $answers + [
                'eventDate' => today()->subDay()->toDateString(),
                'postText' => 'Students from DISC visited a factory.',
                'photoSource' => 'team',
                'photoTeam' => 'ALP',
            ],
        ];
    }

    private function user(string $who): User
    {
        return User::where('email', $who.'@example.test')->firstOrFail();
    }
}
