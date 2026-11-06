<?php

namespace Tests\Feature;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The old LinkedIn form comes in as LinkedIn posts: posted ones closed with their link, recent ones open, old unposted ones cancelled. */
class ImportLinkedInTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_form_is_brought_in_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        $old = now()->subDays(90)->format('d/m/Y');
        $recent = now()->subDays(5)->format('d/m/Y');
        $file = tempnam(sys_get_temp_dir(), 'li');
        file_put_contents($file, implode("\n", [
            'Timestamp,Name,Post/Event Title:,Post/Event Project:,Event Date:,Post/Event Content:,Post/Event City:,Post/Event by Media Team,Upload the post/event Photos,Content Checked,Design,Published,Published Date,link to post ',
            "{$old} 10:00:00,Rami,Factory visit,005 DISC,{$old},Students visited a factory.,002 HMS,Yes,,TRUE,FALSE,TRUE,{$old},https://www.linkedin.com/feed/update/urn:li:activity:1",
            ',,,,,,,,,,,,,',
            "{$old} 11:00:00,Rami,Never posted,006 SZ,{$old},Something.,001 ALP,No,,FALSE,FALSE,FALSE,,",
            "{$recent} 09:30:00,Rami,Graduation,010 LEAP,{$recent},A graduation.,001 ALP,the photos are with the team,,FALSE,FALSE,FALSE,,",
        ]));

        $this->artisan('media:import-linkedin', ['file' => $file])->assertSuccessful();
        $this->artisan('media:import-linkedin', ['file' => $file])->assertSuccessful();   // again: nothing twice
        unlink($file);

        $posts = MediaRequest::where('service', '004')->orderBy('created_at')->get();
        $this->assertCount(3, $posts);

        [$posted, $never, $open] = $posts->all();
        $this->assertSame(S::Cancelled, $never->status);
        $this->assertStringContainsString('Not marked as posted', $never->description);
        $this->assertSame('mine', $never->answer('photoSource'));
        $this->assertSame(S::Delivered, $posted->status);
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:activity:1', $posted->archive_link);
        $this->assertSame(['team', 'HMS'], [$posted->answer('photoSource'), $posted->answer('photoTeam')]);
        $this->assertSame('Students visited a factory.', $posted->answer('postText'));
        $this->assertSame('MR-004-'.now()->subDays(90)->format('y').'-0001', $posted->reference);

        $this->assertSame(S::MediaApproval, $open->status);
        $this->assertStringContainsString('the photos are with the team', $open->description);
        $this->assertSame('ALP', $open->answer('photoTeam'));
    }
}
