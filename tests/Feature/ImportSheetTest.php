<?php

namespace Tests\Feature;

use App\Enums\MediaRequestStatus as S;
use App\Models\Employee;
use App\Models\MediaRequest;
use App\Support\MediaRequests\TeamStats;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** media:import-sheet brings the old Google sheet in as readable history, joining names written in several ways. */
class ImportSheetTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    private string $map;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $row = fn (string $time, string $name, string $section, string $city, string $for, string $title, string $type, string $due, string $ct, string $officer, string $status) => [
            $time, $name, 'x', 'Team Member', $section, $city, $for, $title, $type, 'Yes', '', $due, '4', '', $ct, '', 'FALSE', 'FALSE', '', $officer, $status, '', '', '', 'FALSE', '', '', '',
        ];
        $recent = now()->subDays(5)->format('n/j/Y');
        $rows = [
            $row('1/16/2026 9:21:16', 'angela samin', 'Design', '002 HMS', '006 SZ', 'Courses', 'New', '1/19/2026', 'Approved', 'Hikmat', 'Done'),
            $row('2/2/2026 10:00:00', 'ANGELLA SAMIN', 'Photo', '001 ALP', '011 ITP', 'Opening', 'طلب تصوير', '2/5/2026', 'Approved', 'Mirvat, Michel', ''),
            $row('2/3/2026 10:00:00', 'Toni', 'Design', '002 HMS', 'Partner', 'Flyer', 'Edit', '2/6/2026', 'Rejected', '', ''),
            $row('2/4/2026 10:00:00', 'Lana Abdo', 'Other', '000 SYR', '001 HC', 'Page', 'Publish', '2/6/2026', 'Approved', '', ''),
            $row('2/5/2026 10:00:00', 'Lana Abdo', 'Design', '000 SYR', '001 HC', 'Old open', 'New', '2/9/2026', 'Approved', 'George H', 'In Progress'),
            $row($recent.' 10:00:00', 'Lana Abdo', 'Design', '000 SYR', '001 HC', 'New open', 'New', now()->addDays(5)->format('n/j/Y'), 'Approved', 'George H', 'In Progress'),
            $row('2/6/2026 10:00:00', '0', 'Design', '000 SYR', '001 HC', 'Junk', 'New', '2/9/2026', 'Approved', '', ''),
        ];
        $this->csv = tempnam(sys_get_temp_dir(), 'sheet');
        $h = fopen($this->csv, 'w');
        fputcsv($h, array_fill(0, 28, 'h'), escape: '\\');
        foreach ($rows as $r) {
            fputcsv($h, $r, escape: '\\');
        }
        fclose($h);

        $this->map = tempnam(sys_get_temp_dir(), 'map');
        file_put_contents($this->map, json_encode([
            'drop' => ['0'],
            'people' => ['angela samin' => 'Angela Samin', 'angella samin' => 'Angela Samin', 'toni' => 'Toni Azar|طوني عازار'],
            'officers' => ['hikmat' => 'Hekmat Jouby@ALP', 'mirvat' => 'Mirvat Dham@HMS', 'michel' => 'Michael Sakrouj@HMS', 'george h' => 'George Alhorani@DMS'],
            'programs' => ['006 sz' => 'SZ', '011 itp' => 'ITP', 'partner' => 'PARTNER', '001 hc' => 'HC'],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        @unlink($this->map);
        @unlink(storage_path('app/private/sheet-publishing.csv'));
        parent::tearDown();
    }

    public function test_the_sheet_becomes_history_with_names_joined(): void
    {
        $this->artisan('media:import-sheet', ['file' => $this->csv, '--map' => $this->map])->assertSuccessful();

        $this->assertSame(4, MediaRequest::whereNotNull('imported_from')->count());   // publishing, old open and junk left out
        $this->assertSame(1, Employee::where('first_name_en', 'Angela')->count());    // written two ways, one person

        $done = MediaRequest::where('title', 'Courses')->firstOrFail();
        $this->assertSame(S::Delivered, $done->status);
        $this->assertSame('Hekmat', $done->assignee->first_name_en);
        $this->assertSame('SZ', $done->program->slug);
        $this->assertSame('2026-01-16', $done->created_at->toDateString());
        $this->assertNull($done->deliveryTiming());                                     // no delivery date in the sheet

        $shoot = MediaRequest::where('title', 'Opening')->firstOrFail();
        $this->assertSame(['002', 'shoot', S::Delivered, 'ITP'], [$shoot->service, $shoot->type, $shoot->status, $shoot->program->slug]);
        $this->assertStringContainsString('Michael Sakrouj', $shoot->description);     // the second person who worked on it

        $this->assertSame(S::Rejected, MediaRequest::where('title', 'Flyer')->value('status'));
        $this->assertSame('PARTNER', MediaRequest::where('title', 'Flyer')->first()->program->slug);
        $this->assertSame(S::InProgress, MediaRequest::where('title', 'New open')->value('status'));
        $this->assertFileExists(storage_path('app/private/sheet-publishing.csv'));

        // In the statistics: counted, but not as on time.
        $s = app(TeamStats::class)->for(null);
        $this->assertSame(2, $s['timing']['unknown']);

        // Again: nothing doubles.
        $this->artisan('media:import-sheet', ['file' => $this->csv, '--map' => $this->map])->assertSuccessful();
        $this->assertSame(4, MediaRequest::whereNotNull('imported_from')->count());
    }
}
