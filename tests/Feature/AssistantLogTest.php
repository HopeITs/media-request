<?php

namespace Tests\Feature;

use App\Livewire\MediaRequests\RequestWizard;
use App\Models\MediaAssistantLog;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** What people type to the assistant is kept, so it can be taught the words it misses. */
class AssistantLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_what_the_assistant_understood_is_recorded(): void
    {
        Livewire::actingAs($this->user('rami'))
            ->test(RequestWizard::class)
            ->call('open', null, 'لازمني شهادات لطلاب الديسك بحلب الأسبوع الجاي');

        $log = MediaAssistantLog::sole();
        $this->assertTrue($log->understood);
        $this->assertSame(['001', 'new'], [$log->service, $log->type]);
        $this->assertSame(Program::where('slug', 'DISC')->value('id'), $log->program_id);
        $this->assertSame($this->user('rami')->employee_id, $log->employee_id);
    }

    public function test_a_sentence_it_does_not_understand_is_recorded_as_missed(): void
    {
        Livewire::actingAs($this->user('rami'))
            ->test(RequestWizard::class)
            ->call('open', null, 'بدي شي حلو للعيد');

        $this->assertFalse(MediaAssistantLog::sole()->understood);
    }

    public function test_the_final_request_is_linked_and_a_changed_guess_is_flagged(): void
    {
        Livewire::actingAs($this->user('rami'))
            ->test(RequestWizard::class)
            ->call('open', null, 'لازمني شهادات لطلاب الديسك بحلب الأسبوع الجاي')
            ->call('chooseType', '001', 'edit')
            ->set('answers.editWhat', ['names'])
            ->set('title', 'Volunteers')
            ->call('next')
            ->call('next')
            ->call('next')
            ->call('submit')
            ->assertHasNoErrors();

        $log = MediaAssistantLog::sole();
        $this->assertNotNull($log->media_request_id);
        $this->assertSame(['001', 'edit'], [$log->final_service, $log->final_type]);
        $this->assertTrue($log->corrected);
    }

    public function test_only_people_with_the_log_see_the_page(): void
    {
        $this->actingAs($this->user('maya'))->get('/media-requests/assistant')->assertOk();
        $this->actingAs($this->user('rami'))->get('/media-requests/assistant')->assertForbidden();
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
