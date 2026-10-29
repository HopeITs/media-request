<?php

namespace Tests\Feature;

use App\Livewire\MediaRequests\AssistantLog;
use App\Models\City;
use App\Models\MediaAssistantLesson;
use App\Models\MediaAssistantLog;
use App\Models\Program;
use App\Models\User;
use App\Support\MediaRequests\MediaAssistant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The media manager teaches the assistant the words it did not understand; it reads them first afterwards. */
class TeachAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_word_it_missed_is_taught_and_understood_from_then_on(): void
    {
        $text = 'بدي كرت معايدة للعيد لفريق الوادي';
        $read = fn () => app(MediaAssistant::class)->analyze($text, Program::all(), City::all());
        $this->assertNull($read()['service']);

        $log = MediaAssistantLog::create(['employee_id' => $this->user('rami')->employee_id, 'text' => $text, 'understood' => false]);

        Livewire::actingAs($this->user('maya'))->test(AssistantLog::class)
            ->call('teach', $log->id)->assertSet('phrase', $text)
            ->set('phrase', 'كرت معايدة')->set('kind', '001.new')->set('format', 'invite')
            ->set('city_id', City::where('slug', 'HMS')->value('id'))
            ->call('saveLesson')->assertHasNoErrors()
            ->call('setFilter', 'lessons')->assertSee('كرت معايدة');

        $r = $read();
        $this->assertSame(['001', 'new', 'invite'], [$r['service'], $r['type'], $r['answers']['format'] ?? null]);
        $this->assertSame(City::where('slug', 'HMS')->value('id'), $r['city_id']);
        $this->assertNotNull($log->fresh()->lesson_id);

        // A lesson needs a meaning.
        Livewire::actingAs($this->user('maya'))->test(AssistantLog::class)
            ->set('phrase', 'شي')->call('saveLesson')->assertHasErrors('kind');

        // Forgotten: read as before.
        Livewire::actingAs($this->user('maya'))->test(AssistantLog::class)->call('deleteLesson', MediaAssistantLesson::first()->id);
        $this->assertNull($read()['service']);
    }

    public function test_synonyms_a_generator_tool_and_a_reply_can_be_taught(): void
    {
        $maya = $this->user('maya');
        $read = fn (string $t) => app(MediaAssistant::class)->analyze($t, Program::all(), City::all());

        // Several words for the same thing.
        Livewire::actingAs($maya)->test(AssistantLog::class)
            ->set('phrase', 'لوغو')->set('keywords', "logo, شعار\nلوجو")->set('kind', '001.new')->set('format', 'other')
            ->call('saveLesson')->assertHasNoErrors();
        $this->assertSame('001', $read('بدي شعار جديد للمركز')['service']);
        $this->assertSame('001', $read('need a LOGO')['service']);

        // Done on HC Generator: the form points to the tool.
        Livewire::actingAs($maya)->test(AssistantLog::class)
            ->set('phrase', 'تفريغ فواتير')->set('keywords', 'فواتير بدها تفريغ')->set('tool', 'text')
            ->call('saveLesson')->assertHasNoErrors();
        $this->assertSame('text', app(\App\Support\MediaRequests\HcGenerator::class)->suggest('عندي فواتير بدها تفريغ')['key']);

        // Not the media team's: the form says where it goes.
        Livewire::actingAs($maya)->test(AssistantLog::class)
            ->set('phrase', 'طلب توظيف')->set('reply', 'التوظيف عند الموارد البشرية')->call('saveLesson')->assertHasNoErrors();
        Livewire::actingAs($this->user('rami'))->test(\App\Livewire\MediaRequests\RequestWizard::class)
            ->call('open')->set('prompt', 'بدي اعمل طلب توظيف HR')->assertSee('التوظيف عند الموارد البشرية');

        Livewire::actingAs($maya)->test(AssistantLog::class)->call('setFilter', 'lessons')
            ->assertSee('شعار')->assertSee('HC Generator')->assertSee('التوظيف عند الموارد البشرية');
    }

    public function test_one_word_can_mean_a_request_and_a_generator_tool_together(): void
    {
        Livewire::actingAs($this->user('maya'))->test(AssistantLog::class)
            ->set('phrase', 'ريل')->set('keywords', 'reel')->set('kind', '003.shoot')->set('tool', 'reels')
            ->call('saveLesson')->assertHasNoErrors();

        $this->assertSame(['003', 'shoot'], array_slice(array_values(app(MediaAssistant::class)->analyze('بدي اعمل reel', Program::all(), City::all())), 0, 2));
        $this->assertSame('reels', app(\App\Support\MediaRequests\HcGenerator::class)->suggest('بدي اعمل ريل')['key']);
        Livewire::actingAs($this->user('maya'))->test(AssistantLog::class)->call('setFilter', 'lessons')->assertSee('HC Generator');
    }

    public function test_only_the_media_manager_teaches(): void
    {
        $log = MediaAssistantLog::create(['employee_id' => $this->user('rami')->employee_id, 'text' => 'x y', 'understood' => false]);

        Livewire::actingAs($this->user('fadi'))->test(AssistantLog::class)
            ->assertDontSeeHtml('wire:click="teach(')
            ->call('teach', $log->id)->assertForbidden();
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
