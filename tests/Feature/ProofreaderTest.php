<?php

namespace Tests\Feature;

use App\Livewire\MediaRequests\ProofreaderPanel;
use App\Models\User;
use App\Support\MediaRequests\Proofreader;
use App\Support\MediaRequests\Punctuation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** The proofreader: punctuation always, locally; spelling and grammar by Gemini when a key is set. */
class ProofreaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_spacing_and_punctuation_are_fixed_without_changing_words(): void
    {
        $fix = fn (string $t) => app(Punctuation::class)->fix($t)['text'];

        $this->assertSame('فارس: ب', $fix('فارس : ب'));
        $this->assertSame('مرحبا، كيف حالك؟', $fix('مرحبا ,كيف حالك ؟'));
        $this->assertSame('hello, how are you? Fine.', $fix('hello ,how are you ?Fine.'));
        $this->assertSame('عام 2026 (تجربة)', $fix('عام ٢٠٢٦ ( تجربة )'));
        $this->assertSame('يرجى الحضور. شكرا', $fix('يرجى الحضور.شكرا'));
        // Times, prices and links are left alone.
        $this->assertSame('الساعة 9:30 والسعر 1.5 دولار', $fix('الساعة 9:30 والسعر 1.5 دولار'));
        $this->assertSame('Visit www.example.com now', $fix('Visit www.example.com now'));
    }

    public function test_without_a_key_only_the_local_fixes_run(): void
    {
        config(['media-requests.proofread.key' => '']);
        Http::fake();

        $r = app(Proofreader::class)->check('فارس : ب', 1);

        $this->assertSame('فارس: ب', $r['corrected']);
        $this->assertFalse($r['ai']);
        $this->assertSame('off', $r['aiError']);
        Http::assertNothingSent();
    }

    public function test_gemini_checks_the_text_with_the_team_instructions(): void
    {
        config(['media-requests.proofread.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'language' => 'ar',
            'corrected' => 'ندعوكم للمشاركة في الورشة.',
            'changes' => [['before' => 'للمشاركه', 'after' => 'للمشاركة', 'note' => 'تاء مربوطة']],
            'suggestions' => ['Improve the wording.'],
            'summary' => 'تصحيح إملائي واحد.',
        ], JSON_UNESCAPED_UNICODE)]]]]]])]);

        $r = app(Proofreader::class)->check('ندعوكم للمشاركه في الورشة', 1);

        $this->assertTrue($r['ai']);
        $this->assertSame('ندعوكم للمشاركة في الورشة.', $r['corrected']);
        $this->assertSame('للمشاركة', $r['changes'][0]['after']);
        Http::assertSent(fn ($req) => $req->hasHeader('x-goog-api-key', 'test-key')
            && str_contains($req['systemInstruction']['parts'][0]['text'], 'Indian numerals'));
    }

    public function test_when_gemini_fails_the_local_fixes_still_come_back(): void
    {
        config(['media-requests.proofread.key' => 'test-key']);
        Http::fake(['*' => Http::response('nope', 500)]);

        $r = app(Proofreader::class)->check('فارس : ب', 1);

        $this->assertSame('فارس: ب', $r['corrected']);
        $this->assertSame('down', $r['aiError']);
    }

    public function test_the_window_sends_the_checked_text_back_to_the_field(): void
    {
        config(['media-requests.proofread.key' => '']);
        $rami = User::where('email', 'rami@example.test')->firstOrFail();

        Livewire::actingAs($rami)->test(ProofreaderPanel::class)
            ->dispatch('media-proofread-open', text: 'فارس : ب', target: 'description')
            ->assertSet('show', true)
            ->call('check')
            ->assertSet('result.corrected', 'فارس: ب')
            ->assertSeeHtml('<ins>')
            ->call('accept')
            ->assertDispatched('media-proofread-apply', target: 'description', text: 'فارس: ب')
            ->assertSet('show', false);

        // A target that is not a known field is ignored.
        Livewire::actingAs($rami)->test(ProofreaderPanel::class)
            ->dispatch('media-proofread-open', text: 'x', target: 'password')
            ->assertSet('target', null);
    }

    public function test_the_card_is_on_everyones_home_screen(): void
    {
        foreach (['rami', 'maya'] as $who) {
            $this->actingAs(User::where('email', $who.'@example.test')->firstOrFail())
                ->get('/media-requests/home')->assertOk()->assertSee('proof-text-card', false)->assertSee('proof-file-card', false);
        }
    }

    public function test_a_word_file_is_read_and_written(): void
    {
        $word = app(\App\Support\MediaRequests\WordText::class);
        $bytes = $word->write("السطر الأول\nفارس : ب", true);
        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $bytes);

        $this->assertSame("السطر الأول\nفارس : ب", $word->read($path));
        $this->assertNull($word->read(__FILE__));   // not a Word file
        @unlink($path);
    }

    public function test_an_uploaded_word_file_fills_the_box(): void
    {
        config(['media-requests.proofread.key' => '']);
        $rami = User::where('email', 'rami@example.test')->firstOrFail();
        $docx = \Illuminate\Http\UploadedFile::fake()->createWithContent('Invitation.docx',
            app(\App\Support\MediaRequests\WordText::class)->write('ندعوكم : للحضور', true));

        Livewire::actingAs($rami)->test(ProofreaderPanel::class)
            ->dispatch('media-proofread-open')
            ->set('file', $docx)
            ->assertHasNoErrors()
            ->assertSet('text', 'ندعوكم : للحضور')
            ->assertSet('source', 'Invitation.docx');
    }

    public function test_checks_are_kept_for_their_owner_only(): void
    {
        config(['media-requests.proofread.key' => '']);
        $rami = User::where('email', 'rami@example.test')->firstOrFail();
        $lina = User::where('email', 'lina@example.test')->firstOrFail();

        $panel = Livewire::actingAs($rami)->test(ProofreaderPanel::class)
            ->dispatch('media-proofread-open', text: 'فارس : ب')
            ->call('check');
        $saved = \App\Models\MediaProofread::where('user_id', $rami->id)->sole();
        $this->assertSame('فارس: ب', $saved->corrected);

        $panel->call('download')->assertFileDownloaded('text_checked.docx');

        // The history lists it, with its first line.
        Livewire::actingAs($rami)->test(ProofreaderPanel::class, ['inline' => true])->set('tab', 'history')->assertSee('فارس : ب');

        // Lina cannot open or delete Rami's check.
        Livewire::actingAs($lina)->test(ProofreaderPanel::class)
            ->set('tab', 'history')->call('open')->set('tab', 'history')
            ->assertDontSee('فارس');
        Livewire::actingAs($lina)->test(ProofreaderPanel::class)->call('deleteSaved', $saved->id);
        $this->assertModelExists($saved);

        Livewire::actingAs($rami)->test(ProofreaderPanel::class)->call('deleteSaved', $saved->id);
        $this->assertModelMissing($saved);
    }

    public function test_the_card_checks_in_place_without_a_pop_up(): void
    {
        config(['media-requests.proofread.key' => '']);
        $rami = User::where('email', 'rami@example.test')->firstOrFail();

        Livewire::actingAs($rami)->test(ProofreaderPanel::class, ['inline' => true])
            ->assertSee(__('media.proof.upload'))
            ->assertSee(__('media.proof.gem'))   // the team's Gem, in a new tab
            ->assertSeeHtml('href="https://gemini.google.com/gem/')
            ->assertDontSeeHtml('class="mback"')
            // The open event is for the pop-up; the card keeps what is in it.
            ->set('text', 'فارس : ب')
            ->dispatch('media-proofread-open', text: 'something else')
            ->assertSet('text', 'فارس : ب')
            ->call('check')
            ->assertSet('result.corrected', 'فارس: ب')
            ->call('close')
            ->assertSet('result', null)
            ->assertSet('show', true);
    }
}