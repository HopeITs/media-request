<?php

namespace Tests\Feature;

use App\Livewire\MediaRequests\Drive;
use App\Models\City;
use App\Models\MediaRequest;
use App\Models\Program;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** A result is a link or files; files show as previews, live in program / governorate / request folders, and on the Files page. */
class ResultFilesTest extends TestCase
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

    public function test_a_result_can_be_files_only_and_is_stored_in_its_folders(): void
    {
        $r = $this->inWork();

        $this->w->sendResult($this->user('fadi'), $r, '', null, [
            UploadedFile::fake()->image('poster.png', 800, 600),
            UploadedFile::fake()->create('poster.pdf', 120, 'application/pdf'),
        ]);

        $r = $r->fresh();
        $this->assertNull($r->result_link);
        $files = $r->getMedia('results');
        $this->assertCount(2, $files);
        $this->assertSame(1, $files->first()->getCustomProperty('version'));
        $this->assertSame('result', $files->first()->getCustomProperty('kind'));
        // On the disk: program / governorate / request number / file id.
        $this->assertStringContainsString('005_DISC/001_ALP/'.$r->reference.'/', str_replace('\\', '/', $files->first()->getPath()));
        Storage::disk('local')->assertExists(str_replace('\\', '/', $files->first()->getPathRelativeToRoot()));
    }

    public function test_a_link_or_a_file_is_needed(): void
    {
        $r = $this->inWork();

        try {
            $this->w->sendResult($this->user('fadi'), $r, '', null, []);
            $this->fail('Nothing sent should not be accepted');
        } catch (ValidationException $e) {
            $this->assertSame(__('media.err.linkOrFile'), $e->errors()['link'][0]);
        }

        $this->w->shareDraft($this->user('fadi'), $r->fresh(), null, null, [UploadedFile::fake()->image('idea.jpg')]);
        $this->assertSame('draft', $r->fresh()->getMedia('results')->first()->getCustomProperty('kind'));
    }

    public function test_images_preview_in_the_page_and_svg_never_does(): void
    {
        $r = $this->inWork();
        $this->w->sendResult($this->user('fadi'), $r, null, null, [
            UploadedFile::fake()->image('a.png'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ]);
        [$png, $svg] = [$r->fresh()->getMedia('results')->firstWhere('file_name', 'a.png'), $r->fresh()->getMedia('results')->firstWhere('file_name', 'logo.svg')];

        $this->actingAs($this->user('maya'))->get(route('media-requests.files', ['media' => $png, 'view' => 1]))
            ->assertOk()->assertHeader('Content-Disposition', 'inline; filename="a.png"');
        $this->actingAs($this->user('maya'))->get(route('media-requests.files', ['media' => $svg, 'view' => 1]))
            ->assertOk()->assertDownload('logo.svg');   // downloaded, never shown in the page

        // The reviewers see the preview on the request page; someone outside cannot open the file.
        $this->actingAs($this->user('maya'))->get('/media-requests/'.$r->id)->assertSee('resgal', false);
        $this->actingAs($this->outsider())->get(route('media-requests.files', $png))->assertForbidden();
    }

    public function test_the_files_page_shows_folders_each_person_may_see(): void
    {
        $r = $this->inWork();
        $this->w->sendResult($this->user('fadi'), $r, null, null, [UploadedFile::fake()->image('final.png')]);

        Livewire::actingAs($this->user('rami'))->test(Drive::class)
            ->assertSee('005 DISC')
            ->call('open', 'program', $r->program_id)->assertSee('001 ALP')
            ->call('open', 'city', $r->city_id)->assertSee($r->reference)
            ->call('open', 'request', $r->id)->assertSee('final.png')
            ->call('up', 'root')->assertSee('005 DISC');

        // Someone with no part in it sees no folder and cannot open the request by its id.
        Livewire::actingAs($this->outsider())->test(Drive::class)
            ->assertDontSee('005 DISC')
            ->set('program', $r->program_id)->set('city', $r->city_id)->set('request', $r->id)
            ->assertDontSee('final.png');

        $this->actingAs($this->user('rami'))->get('/media-requests/drive')->assertOk();
    }

    private function inWork(): MediaRequest
    {
        $r = $this->w->submit($this->user('rami'), [
            'service' => '001', 'type' => 'edit',
            'program_id' => Program::where('slug', 'DISC')->value('id'),
            'city_id' => City::where('slug', 'ALP')->value('id'),
            'title' => 'Files', 'needed_by' => today()->addWeekdays(8)->toDateString(),
            'answers' => ['editWhat' => ['text']],
        ]);
        $this->w->approveProgram($this->user('lina'), $r);
        $this->w->approveMedia($this->user('maya'), $r->fresh(), 'normal', null, $this->user('fadi')->employee_id);
        $this->w->start($this->user('fadi'), $r->fresh());

        return $r->fresh();
    }

    private function outsider(): User
    {
        $e = \App\Models\Employee::create(['username' => 'out', 'first_name_en' => 'Out', 'first_name_ar' => 'Out']);

        return User::create(['email' => 'out@example.test', 'name' => 'Out', 'password' => 'password', 'role' => 'employee', 'employee_id' => $e->id]);
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
