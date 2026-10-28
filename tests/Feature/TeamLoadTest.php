<?php

namespace Tests\Feature;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\TeamLoad;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** When the people of a field are already full, a new request's date moves out; never a weekend. */
class TeamLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_sizes_differ_by_what_is_made(): void
    {
        $load = app(TeamLoad::class);

        $this->assertSame(3.0, $load->effort('001', 'new', ['format' => 'newsletter']));
        $this->assertSame(3.0, $load->effort('001', 'new', ['format' => 'brochure', 'brochureType' => 'booklet']));
        $this->assertSame(2.0, $load->effort('001', 'new', ['format' => 'brochure', 'brochureType' => 'trifold']));
        $this->assertSame(1.0, $load->effort('001', 'new', ['format' => 'poster']));
        $this->assertSame(0.5, $load->effort('001', 'edit'));
        $this->assertSame(3.0, $load->effort('003', 'export'));
    }

    public function test_a_full_team_adds_days(): void
    {
        $load = app(TeamLoad::class);
        $before = $load->of('design');

        // Design has two people (Maya and Fadi): four magazines in work is 12 units, 8 above what they carry.
        foreach (range(1, 4) as $i) {
            $this->submit(['format' => 'newsletter', 'newsMedium' => ['interactive'], 'pages' => 12, 'langs' => ['ar']], 'Magazine'.$i)
                ->forceFill(['status' => S::InProgress])->save();
        }

        $after = $load->of('design');
        $this->assertSame(2, $after['people']);
        $this->assertGreaterThan($before['units'], $after['units']);
        $this->assertGreaterThanOrEqual(1, $after['extraDays']);
        $this->assertLessThanOrEqual(5, $after['extraDays']);

        // Video has no work in progress: no wait.
        $this->assertSame(0, $load->of('montage')['extraDays']);
    }

    public function test_no_delivery_at_the_weekend(): void
    {
        $rami = User::where('email', 'rami@example.test')->firstOrFail();
        $saturday = today()->next(\Carbon\CarbonInterface::SATURDAY)->addWeek()->toDateString();

        try {
            $this->submit(['editWhat' => ['text']], 'Weekend', 'edit', $saturday);
            $this->fail('A Saturday should not be accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('needed_by', $e->errors());
            $this->assertSame(__('media.err.weekend'), $e->errors()['needed_by'][0]);
        }
    }

    private function submit(array $answers, string $title, string $type = 'new', ?string $date = null): MediaRequest
    {
        return app(MediaRequestWorkflow::class)->submit(User::where('email', 'rami@example.test')->firstOrFail(), [
            'service' => '001', 'type' => $type,
            'program_id' => \App\Models\Program::where('slug', 'DISC')->value('id'),
            'city_id' => \App\Models\City::where('slug', 'ALP')->value('id'),
            'title' => $title, 'needed_by' => $date ?? today()->addWeekdays(15)->toDateString(),
            'answers' => $answers,
        ]);
    }
}