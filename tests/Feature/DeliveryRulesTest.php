<?php

namespace Tests\Feature;

use App\Livewire\MediaRequests\RequestWizard;
use App\Models\City;
use App\Models\MediaRequest;
use App\Models\Program;
use App\Models\User;
use App\Notifications\MediaRequestDelivered;
use App\Services\MediaRequestWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Delivery is in the app: an email with the details, finished files for the requester, open files for the team only. */
class DeliveryRulesTest extends TestCase
{
    use RefreshDatabase;

    private MediaRequestWorkflow $w;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->w = app(MediaRequestWorkflow::class);
    }

    public function test_the_requester_and_program_manager_get_an_email_when_it_is_delivered(): void
    {
        $r = $this->delivered();

        Notification::assertSentTo($this->user('rami'), MediaRequestDelivered::class);
        Notification::assertSentTo($this->user('lina'), MediaRequestDelivered::class);   // manages DISC
        Notification::assertNotSentTo($this->user('fadi'), MediaRequestDelivered::class);

        $html = (new MediaRequestDelivered($r))->toMail($this->user('rami'))->render();
        $this->assertStringContainsString($r->reference, $html);
        $this->assertStringContainsString('Fadi', $html);                       // who did it
        $this->assertStringContainsString(__('media.mail.delivered', [], 'ar'), $html);
    }

    public function test_open_files_stay_with_the_media_team(): void
    {
        $r = $this->delivered([UploadedFile::fake()->image('final.png'), UploadedFile::fake()->create('final.ai', 50)]);
        [$png, $ai] = [$r->getMedia('results')->firstWhere('file_name', 'final.png'), $r->getMedia('results')->firstWhere('file_name', 'final.ai')];

        $this->actingAs($this->user('rami'))->get(route('media-requests.files', $png))->assertOk();
        $this->actingAs($this->user('rami'))->get(route('media-requests.files', $ai))->assertForbidden();
        $this->actingAs($this->user('maya'))->get(route('media-requests.files', $ai))->assertOk();

        $page = $this->actingAs($this->user('rami'))->get('/media-requests/'.$r->id)->getContent();
        $this->assertStringNotContainsString('final.ai', $page);
        $this->assertStringNotContainsString('sample-archive', $page);         // the archive link too
        $this->assertStringContainsString('final.ai', $this->actingAs($this->user('maya'))->get('/media-requests/'.$r->id)->getContent());
    }

    public function test_the_media_team_enters_a_request_for_someone_who_asked_by_phone(): void
    {
        $data = $this->data();

        $r = $this->w->submit($this->user('fadi'), $data + ['for' => $this->user('rami')->employee_id]);
        $this->assertSame($this->user('rami')->employee_id, $r->requester_id);
        $this->assertTrue($r->needs_program_approval);                          // Rami's program manager still approves first
        Notification::assertSentTo($this->user('rami'), \App\Notifications\MediaRequestUpdated::class);

        // Someone outside the team cannot.
        $this->expectException(ValidationException::class);
        $this->w->submit($this->user('rami'), $data + ['for' => $this->user('lina')->employee_id]);
    }

    public function test_the_form_shows_the_person_choice_to_the_team_only_and_no_delivery_question(): void
    {
        Livewire::actingAs($this->user('fadi'))->test(RequestWizard::class)->call('open')
            ->set('service', '001')->set('type', 'edit')->set('step', 3)
            ->assertSee(__('media.f.for'));
        Livewire::actingAs($this->user('rami'))->test(RequestWizard::class)->call('open')
            ->set('service', '001')->set('type', 'edit')->set('step', 3)
            ->assertDontSee(__('media.f.for'));
        Livewire::actingAs($this->user('rami'))->test(RequestWizard::class)->call('open')
            ->set('service', '001')->set('type', 'edit')->set('step', 4)
            ->assertSee(__('media.wz.proofGo'))->assertDontSee(__('media.f.channel'));
    }

    private function delivered(array $files = []): MediaRequest
    {
        $r = $this->w->submit($this->user('rami'), $this->data());
        $this->w->approveProgram($this->user('lina'), $r);
        $this->w->approveMedia($this->user('maya'), $r->fresh(), 'normal', null, $this->user('fadi')->employee_id);
        $this->w->start($this->user('fadi'), $r->fresh());
        $this->w->sendResult($this->user('fadi'), $r->fresh(), $files ? null : 'https://drive.google.com/x', null, $files);
        $this->w->approveInternal($this->user('maya'), $r->fresh());
        $this->w->approveResult($this->user('rami'), $r->fresh());
        $this->w->deliver($this->user('fadi'), $r->fresh(), 'https://drive.google.com/drive/folders/sample-archive');

        return $r->fresh();
    }

    private function data(): array
    {
        return [
            'service' => '001', 'type' => 'edit', 'program_id' => Program::where('slug', 'DISC')->value('id'),
            'city_id' => City::where('slug', 'ALP')->value('id'), 'title' => 'Mail test',
            'needed_by' => today()->addWeekdays(8)->toDateString(), 'answers' => ['editWhat' => ['text']],
        ];
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
