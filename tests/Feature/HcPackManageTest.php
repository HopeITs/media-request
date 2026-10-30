<?php

namespace Tests\Feature;

use App\Livewire\MediaRequests\Pack;
use App\Models\MediaPackFile;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\HcPack;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The media manager keeps HC_PACK in the app; where a file has versions, the newest is offered. */
class HcPackManageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_media_manager_adds_replaces_and_removes_files(): void
    {
        $maya = $this->user('maya');
        $path = '001 Logos/001 Projects/099 Test/099_TEST.svg';

        Livewire::actingAs($maya)->test(Pack::class)
            ->set('adding', true)->set('newGroup', 'projects')->set('newFolder', '099 Test')
            ->set('newFile', UploadedFile::fake()->create('099_TEST.svg', 4, 'image/svg+xml'))
            ->call('addFile')->assertHasNoErrors()->assertSee('099_TEST.svg');
        $this->assertTrue($this->pack()->all()->contains('path', $path));
        $first = MediaPackFile::where('path', $path)->value('stored');
        Storage::disk('local')->assertExists($first);

        // A newer version, same kind: same place, the old upload gone.
        Livewire::actingAs($maya)->test(Pack::class)->call('startReplace', $path)
            ->set('replacement', UploadedFile::fake()->create('new.svg', 6, 'image/svg+xml'))->call('replaceFile')->assertHasNoErrors();
        Storage::disk('local')->assertMissing($first);
        $this->assertSame(6 * 1024, $this->pack()->all()->firstWhere('path', $path)['size']);

        // Another kind: the new name with its own extension, the old one taken out.
        Livewire::actingAs($maya)->test(Pack::class)->call('startReplace', $path)
            ->set('replacement', UploadedFile::fake()->create('logo.png', 6, 'image/png'))->call('replaceFile')->assertHasNoErrors();
        $this->assertFalse($this->pack()->all()->contains('path', $path));
        $this->assertTrue($this->pack()->all()->contains('path', '001 Logos/001 Projects/099 Test/099_TEST.png'));

        // A drive file taken out: no longer offered, still named by requests made before.
        $drive = $this->pack()->all()->first()['path'];
        Livewire::actingAs($maya)->test(Pack::class)->call('removeFile', $drive);
        $this->assertFalse($this->pack()->all()->contains('path', $drive));
        $this->assertTrue($this->pack()->find([$drive])->first()['removed']);
    }

    public function test_only_the_media_manager_keeps_the_pack(): void
    {
        $fadi = $this->user('fadi');   // on the team, not the manager

        Livewire::actingAs($fadi)->test(Pack::class)->assertDontSee(__('media.hp.m.add'))
            ->call('removeFile', $this->pack()->all()->first()['path'])->assertForbidden();
    }

    public function test_the_newest_version_is_offered_and_a_series_is_kept_whole(): void
    {
        $paths = $this->pack()->all()->pluck('path');

        $this->assertContains('001 Logos/001 Projects/003 F2F/003_F2F_EN_TEXT (2).svg', $paths);
        $this->assertNotContains('001 Logos/001 Projects/003 F2F/003_F2F_EN_TEXT.svg', $paths);
        // Twelve different backgrounds, numbered: all kept.
        $this->assertCount(12, $paths->filter(fn ($p) => str_contains($p, 'HC 2023 Xmas (')));
        $this->assertCount(3, $paths->filter(fn ($p) => str_contains($p, 'XmasBCK2023')));
    }

    public function test_a_kept_file_is_downloaded_by_the_team_and_by_the_requester_once_delivered(): void
    {
        $maya = $this->user('maya');
        Livewire::actingAs($maya)->test(Pack::class)
            ->set('newGroup', 'projects')->set('newFile', UploadedFile::fake()->create('099_TEST.svg', 4, 'image/svg+xml'))->call('addFile');
        $file = MediaPackFile::firstOrFail();

        $request = app(MediaRequestWorkflow::class)->requestPack($this->user('rami'), [$file->path]);
        $url = route('media-requests.pack.file', ['file' => $file->id, 'r' => $request->id]);

        $this->actingAs($this->user('fadi'))->get($url)->assertOk();          // the team, to send it
        $this->actingAs($this->user('rami'))->get($url)->assertForbidden();   // not yet delivered
        app(MediaRequestWorkflow::class)->deliver($this->user('fadi'), $request);
        $this->actingAs($this->user('rami'))->get($url)->assertOk();
        $this->actingAs($this->user('lina'))->get(route('media-requests.pack.file', $file->id))->assertForbidden();
    }

    private function pack(): HcPack
    {
        return new HcPack;
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
