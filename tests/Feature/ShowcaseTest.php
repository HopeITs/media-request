<?php

namespace Tests\Feature;

use App\Livewire\MediaRequests\Dashboard;
use App\Models\City;
use App\Models\Employee;
use App\Models\MediaRequest;
use App\Models\Program;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The Latest designs slideshow: approved (delivered) designs for everyone; the request only for those who may open it. */
class ShowcaseTest extends TestCase
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

    public function test_everyone_sees_approved_designs_and_only_allowed_people_the_request(): void
    {
        $r = $this->request();
        $this->w->shareDraft($this->user('fadi'), $r->fresh(), null, null, [UploadedFile::fake()->image('draft.png')]);
        $this->w->sendResult($this->user('fadi'), $r->fresh(), null, null, [UploadedFile::fake()->image('final.png'), UploadedFile::fake()->create('final.ai', 20)]);
        $media = fn (string $name) => $r->fresh()->getMedia('results')->firstWhere('file_name', $name);

        // Not delivered yet: not on the slideshow, the picture not served.
        $outsider = $this->outsider();
        $this->actingAs($outsider)->get(route('media-requests.showcase', $media('final.png')))->assertNotFound();

        $this->w->approveInternal($this->user('maya'), $r->fresh());
        $this->w->approveResult($this->user('rami'), $r->fresh());
        $this->w->deliver($this->user('fadi'), $r->fresh(), 'https://drive.google.com/drive/folders/x');

        // Delivered: anyone signed in sees the final picture; never the draft or the open file.
        $this->actingAs($outsider)->get(route('media-requests.showcase', $media('final.png')))->assertOk();
        $this->actingAs($outsider)->get(route('media-requests.showcase', $media('draft.png')))->assertNotFound();
        $this->actingAs($outsider)->get(route('media-requests.showcase', $media('final.ai')))->assertNotFound();
        $this->actingAs($outsider)->get(route('media-requests.files', $media('final.png')))->assertForbidden();   // the request's files stay private

        $slides = fn (User $u) => Livewire::actingAs($u)->test(Dashboard::class)->viewData('showcase');
        $this->assertSame([false], array_column($slides($outsider), 'canOpen'));
        $this->assertSame([true], array_column($slides($this->user('rami')), 'canOpen'));

        // Someone outside cannot open the request from the slideshow either.
        Livewire::actingAs($outsider)->test(Dashboard::class)->call('openRequest', $r->id)->assertSet('open', null);
    }

    private function request(): MediaRequest
    {
        $r = $this->w->submit($this->user('rami'), [
            'service' => '001', 'type' => 'edit', 'program_id' => Program::where('slug', 'DISC')->value('id'),
            'city_id' => City::where('slug', 'ALP')->value('id'), 'title' => 'Showcase',
            'needed_by' => today()->addWeekdays(8)->toDateString(), 'answers' => ['editWhat' => ['text']],
        ]);
        $this->w->approveProgram($this->user('lina'), $r);
        $this->w->approveMedia($this->user('maya'), $r->fresh(), 'normal', null, $this->user('fadi')->employee_id);
        $this->w->start($this->user('fadi'), $r->fresh());

        return $r->fresh();
    }

    private function outsider(): User
    {
        $e = Employee::create(['username' => 'out', 'first_name_en' => 'Out', 'first_name_ar' => 'Out']);

        return User::create(['email' => 'out@example.test', 'name' => 'Out', 'password' => 'password', 'role' => 'employee', 'employee_id' => $e->id]);
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
