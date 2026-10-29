<?php

namespace Tests\Feature;

use App\Livewire\MediaRequests\Dashboard;
use App\Models\MediaDashboard;
use App\Models\User;
use App\Support\MediaRequests\DashboardLayout;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Each person's home screen: their tiles, sizes, order, pins, and private notes. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_people_only_get_the_tiles_they_may_use(): void
    {
        $cards = app(DashboardLayout::class);
        $rami = array_column($cards->defaults($this->user('rami')), 'key');
        $maya = array_column($cards->defaults($this->user('maya')), 'key');

        $this->assertNotContains('team', $rami);
        $this->assertNotContains('app.board', $rami);
        $this->assertContains('app.new', $rami);
        $this->assertContains('team', $maya);
        $this->assertContains('app.board', $maya);
    }

    public function test_a_card_opens_only_a_page_the_person_may_see(): void
    {
        $cards = app(DashboardLayout::class);
        [$rami, $maya] = [$this->user('rami'), $this->user('maya')];

        $this->assertSame(route('media-requests.board'), $cards->page($maya, 'flow'));
        $this->assertNull($cards->page($rami, 'flow'));
        $this->assertSame(route('media-requests.index'), $cards->page($rami, 'decide'));
        $this->assertSame(route('media-requests.pack'), $cards->page($rami, 'pack'));
        $this->assertNull($cards->page($rami, 'clock'));
        $this->assertNull($cards->page($rami, 'app.my'));   // shortcuts open with one click
    }

    public function test_hc_pack_works_from_the_home_screen(): void
    {
        $file = app(\App\Support\MediaRequests\HcPack::class)->all()->first();

        $quick = Livewire::actingAs($this->user('rami'))->test(\App\Livewire\MediaRequests\PackQuick::class)
            ->assertSee(__('media.hp.'.$file['group']))                     // the groups before anything is typed
            ->set('search', 'logo')
            ->assertSeeHtml('class="pq-list"')                               // results here, no new page
            ->set('selected', [$file['path']])
            ->call('requestFiles');

        $request = \App\Models\MediaRequest::latest('id')->first();
        $quick->assertRedirect(route('media-requests.show', $request));
        $this->assertSame([$file['path']], $request->pack_files);
    }

    public function test_a_size_set_by_hand_is_kept_as_set(): void
    {
        $rami = $this->user('rami');
        $layout = app(DashboardLayout::class)->defaults($rami);
        foreach ($layout as &$item) {
            if ($item['key'] === 'calendar') {
                $item = ['size' => '3x3', 'fixed' => true] + $item;
            }
        }

        Livewire::actingAs($rami)->test(Dashboard::class)->call('saveLayout', $layout);
        $saved = collect($this->layoutOf($rami))->keyBy('key');

        $this->assertSame(['3x3', true], [$saved['calendar']['size'], $saved['calendar']['fixed']]);
        $this->assertArrayNotHasKey('fixed', $saved['notes']);
    }

    public function test_a_big_shortcut_shows_its_data_as_a_card(): void
    {
        $this->assertSame([4, 4], app(DashboardLayout::class)->bounds('app.stats')['max']);
        // And the other way: a card goes down to an icon, which opens it at its usual size.
        $this->assertSame(['min' => [1, 1], 'max' => [12, 6], 'card' => [4, 3], 'full' => [8, 3]], app(DashboardLayout::class)->bounds('flow'));

        Livewire::actingAs($this->user('maya'))->test(Dashboard::class)
            ->assertSeeHtml('class="appcard"')->assertSeeHtml('class="app wmini"')
            ->assertSee(__('media.dash.ac.deliveredMonth'))
            ->assertSee(__('media.dash.ac.toTeach'))
            ->assertSee('Meeting Transcription');
    }

    public function test_the_browser_layout_is_saved_and_cleaned(): void
    {
        $rami = $this->user('rami');
        $layout = app(DashboardLayout::class)->defaults($rami);
        $byKey = collect($layout)->keyBy('key');

        $sent = [
            ['key' => 'clock', 'size' => '19x9', 'pinned' => false, 'hidden' => false],   // too big: kept inside the clock's maximum (12×6)
            ['key' => 'calendar', 'size' => '6x5', 'pinned' => true, 'hidden' => false],
            ['key' => 'notes', 'size' => '0x1', 'pinned' => false, 'hidden' => false],    // too small: raised to the minimum (1×1, an icon)
            ['key' => 'waiting', 'size' => 'nonsense', 'pinned' => false, 'hidden' => false], // unreadable: the default (4×6)
            ['key' => 'team', 'size' => '4x2', 'pinned' => false, 'hidden' => false],     // not Rami's: dropped
            ['key' => 'app.pack', 'size' => '5x5', 'pinned' => false, 'hidden' => true],  // shortcuts go up to 4×4 (a card)
            ['key' => 'made-up', 'size' => '4x4'],
            ...$byKey->except(['clock', 'calendar', 'notes', 'waiting', 'app.pack'])->values()->all(),
        ];

        Livewire::actingAs($rami)->test(Dashboard::class)->call('saveLayout', $sent);

        $saved = collect($this->layoutOf($rami))->keyBy('key');
        $this->assertSame('calendar', $this->layoutOf($rami)[0]['key']);   // pinned first
        $this->assertSame('6x5', $saved['calendar']['size']);
        $this->assertSame('12x6', $saved['clock']['size']);
        $this->assertSame('1x1', $saved['notes']['size']);
        $this->assertSame('4x6', $saved['waiting']['size']);
        $this->assertSame('4x4', $saved['app.pack']['size']);
        $this->assertTrue($saved['app.pack']['hidden']);
        $this->assertFalse($saved->has('team'));
        $this->assertFalse($saved->has('made-up'));
    }

    public function test_the_clock_look_is_saved_and_checked(): void
    {
        $rami = $this->user('rami');

        Livewire::actingAs($rami)->test(Dashboard::class)->call('saveLayout', [
            ['key' => 'clock', 'size' => '2x2', 'style' => 'analog'],
            ['key' => 'notes', 'size' => '4x2', 'style' => 'analog'],   // notes have no looks: dropped
        ]);
        $saved = collect($this->layoutOf($rami))->keyBy('key');
        $this->assertSame('analog', $saved['clock']['style']);
        $this->assertArrayNotHasKey('style', $saved['notes']);

        Livewire::actingAs($rami)->test(Dashboard::class)->call('saveLayout', [['key' => 'clock', 'style' => 'sundial']]);
        $this->assertSame('digital', collect($this->layoutOf($rami))->firstWhere('key', 'clock')['style']);
    }

    public function test_layouts_saved_with_the_old_named_sizes_still_load(): void
    {
        $rami = $this->user('rami');
        \App\Models\MediaDashboard::create(['user_id' => $rami->id, 'layout' => [
            ['key' => 'calendar', 'size' => 'l'], ['key' => 'clock', 'size' => 's'], ['key' => 'ask', 'size' => 'bar'],
        ]]);

        $saved = collect($this->layoutOf($rami))->keyBy('key');
        $this->assertSame(['4x4', '2x2', '8x1'], [$saved['calendar']['size'], $saved['clock']['size'], $saved['ask']['size']]);
    }

    public function test_reset_goes_back_to_the_default_and_keeps_notes(): void
    {
        $rami = $this->user('rami');

        Livewire::actingAs($rami)->test(Dashboard::class)
            ->set('notes', 'Call the printer on Monday')
            ->call('saveNotes')
            ->call('saveLayout', [['key' => 'clock', 'size' => '4x2', 'pinned' => true, 'hidden' => false]])
            ->call('resetLayout');

        $this->assertSame(app(DashboardLayout::class)->defaults($rami), $this->layoutOf($rami));
        $this->assertSame('Call the printer on Monday', MediaDashboard::where('user_id', $rami->id)->value('notes'));
    }

    public function test_notes_are_private(): void
    {
        Livewire::actingAs($this->user('rami'))->test(Dashboard::class)->set('notes', 'Rami only')->call('saveNotes');

        Livewire::actingAs($this->user('lina'))->test(Dashboard::class)
            ->assertSet('notes', '')
            ->assertDontSee('Rami only');
    }

    public function test_home_screen_renders_for_everyone_in_both_languages(): void
    {
        foreach (['rami', 'lina', 'maya', 'fadi', 'admin'] as $who) {
            foreach (['ar', 'en'] as $locale) {
                $this->actingAs($this->user($who))->withSession(['locale' => $locale])
                    ->get('/media-requests/home?month=2026-02')
                    ->assertOk()
                    ->assertSee('mrHomeScreen', false);
            }
        }

        // A made-up month falls back to this month instead of failing.
        $this->actingAs($this->user('rami'))->get('/media-requests/home?month=nonsense')->assertOk();
    }

    private function layoutOf(User $user): array
    {
        return app(DashboardLayout::class)->clean($user, MediaDashboard::where('user_id', $user->id)->value('layout'));
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
