<?php

namespace Tests\Feature;

use App\Livewire\MediaRequests\RequestWizard;
use App\Models\City;
use App\Models\Program;
use App\Models\User;
use App\Support\MediaRequests\MediaAssistant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** How the request assistant reads what people write, in their own words. */
class AssistantReadingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-10-30 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_greeting_cards_are_a_new_design_and_the_occasion_is_not_asked_again(): void
    {
        $r = $this->read('كروت معايدة لطلاب المدارس بحفلة عيد الميلاد');

        $this->assertSame(['001', 'new', 'other'], [$r['service'], $r['type'], $r['answers']['format']]);
        $this->assertNotContains('topic', array_column($r['questions'], 'id'));
    }

    public function test_a_named_day_print_copies_and_the_place_are_read(): void
    {
        $r = $this->read('بدنا بوستر لحفلة الميلاد بحمص يوم 20 كانون الأول، ومطبوع 50 نسخة');

        $this->assertSame('poster', $r['answers']['format']);
        $this->assertSame(['yes', 50], [$r['answers']['alsoPrint'], $r['answers']['printQty']]);
        $this->assertSame('2026-12-20', $r['needed_by']);
        $this->assertSame(City::where('slug', 'HMS')->value('id'), $r['city_id']);

        $this->assertSame('2026-12-12', $this->read('A poster for the graduation on December 12, printed 200 copies')['needed_by']);
        $this->assertSame('2027-04-05', $this->read('تصميم لافتة للمعرض 5 ابريل')['needed_by']);   // past this year: next year
        $this->assertSame('print', $this->read('اطبع 100 شهادة')['type']);                          // printing a ready file stays so
    }

    public function test_april_is_not_a_reel_and_a_shoot_takes_its_day_without_asking(): void
    {
        $this->assertSame(['001', 'production'], array_slice(array_values($this->read('تصميم لافتة للمعرض 5 ابريل')), 0, 2));

        $r = $this->read('تصوير يوم المتطوعين بحمص ١٥/١١');
        $this->assertSame(['002', 'shoot', '2026-11-15'], [$r['service'], $r['type'], $r['answers']['eventDate']]);
        $this->assertNotContains('eventDate', array_column($r['questions'], 'id'));
    }

    public function test_what_they_wrote_goes_to_the_team_as_the_brief(): void
    {
        $text = 'كروت معايدة لطلاب المدارس بحفلة عيد الميلاد';

        Livewire::actingAs(User::where('email', 'rami@example.test')->firstOrFail())->test(RequestWizard::class)
            ->call('open')->set('prompt', $text)->call('analyze')
            ->assertSet('description', $text)
            ->set('prompt', $text.' بحلب')->call('analyze')->assertSet('description', $text.' بحلب')   // follows the new words
            ->set('description', 'My own brief')->call('analyze')->assertSet('description', 'My own brief');
    }

    private function read(string $text): array
    {
        return app(MediaAssistant::class)->analyze($text, Program::all(), City::all());
    }
}
