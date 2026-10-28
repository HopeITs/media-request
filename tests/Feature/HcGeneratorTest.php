<?php

namespace Tests\Feature;

use App\Livewire\MediaRequests\RequestWizard;
use App\Models\User;
use App\Support\MediaRequests\HcGenerator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Requests that HC Generator can make are pointed there first; the request is still possible. */
class HcGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_picks_the_right_tool_from_what_was_written(): void
    {
        $gen = app(HcGenerator::class);
        $tool = fn (string $text) => $gen->suggest($text)['key'] ?? null;

        $this->assertSame('reels', $tool('بدي ريل سريع لفعالية الديسك'));
        $this->assertSame('reels', $tool('لخّص هالمحاضرة بفيديو قصير'));
        $this->assertSame('poster', $tool('بوستر فتح باب التسجيل لستادي زون'));
        $this->assertSame('poster', $tool('A story for the LEAP vacancy'));
        $this->assertSame('thumbnail', $tool('Thumbnail for the new YouTube video'));
        $this->assertSame('guidelines', $tool('دقّق هالتقرير وحطه على ترويسة حلب'));
        $this->assertSame('text', $tool('طلّعلي النص من هالـ PDF'));
        $this->assertSame('meeting', $tool('فرّغلي تسجيل الاجتماع'));
        $this->assertSame('https://post-gen.hcsyria-apps.org/reels-gen/', $gen->suggest('reel')['url']);
    }

    public function test_work_the_site_cannot_do_is_not_sent_there(): void
    {
        $gen = app(HcGenerator::class);

        $this->assertNull($gen->suggest('طباعة بوستر A3 للمعرض'));   // printing needs the team
        $this->assertNull($gen->suggest('رول أب للفعالية'));
        $this->assertNull($gen->suggest('شهادات لطلاب الديسك'));
        $this->assertNull($gen->suggest(''));
    }

    public function test_picking_a_type_it_can_make_also_points_there(): void
    {
        $gen = app(HcGenerator::class);

        $this->assertSame('poster', $gen->suggest('', '001', 'new', ['format' => 'social'])['key']);
        $this->assertSame('thumbnail', $gen->suggest('', '001', 'new', ['format' => 'cover', 'coverKind' => ['youtube']])['key']);
        $this->assertNull($gen->suggest('', '001', 'new', ['format' => 'cover', 'coverKind' => ['gform']]));
        $this->assertNull($gen->suggest('', '001', 'new', ['format' => 'cert']));
    }

    public function test_the_form_shows_the_tool_and_still_lets_you_send(): void
    {
        Notification::fake();
        $this->seed(DatabaseSeeder::class);
        $rami = User::where('email', 'rami@example.test')->firstOrFail();

        Livewire::actingAs($rami)->test(RequestWizard::class)
            ->call('open', null, 'بدي ريل لفعالية حلب')
            ->assertSee('Reels Gen')
            ->assertSee('https://post-gen.hcsyria-apps.org/reels-gen/', false)
            ->assertSet('service', '003');   // the request is still filled in

        // HC_PACK only comes up when they ask for something it holds, not for every program name.
        Livewire::actingAs($rami)->test(RequestWizard::class)
            ->call('open', null, 'بوستر ستادي زون')
            ->assertSee('Poster Maker')
            ->assertDontSee('006_SZ');

        $this->actingAs($rami)->get('/media-requests/home')->assertOk()->assertSee('post-gen.hcsyria-apps.org', false);
    }
}
