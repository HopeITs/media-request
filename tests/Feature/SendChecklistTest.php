<?php

namespace Tests\Feature;

use App\Enums\MediaRequestStatus as S;
use App\Livewire\MediaRequests\Show;
use App\Models\City;
use App\Models\Program;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** A design goes to the review only once the designer ticked what the review sent back most: text, logos, facts, versions. */
class SendChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_design_is_sent_only_with_every_line_ticked(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $w = app(MediaRequestWorkflow::class);
        $fadi = $this->user('fadi');

        $r = $w->submit($this->user('lina'), [
            'service' => '001', 'type' => 'new', 'title' => 'Open day',
            'program_id' => Program::where('slug', 'DISC')->value('id'), 'city_id' => City::where('slug', 'ALP')->value('id'),
            'needed_by' => today()->addWeekdays(8)->toDateString(),
            'answers' => ['format' => 'poster', 'versions' => ['story'], 'langs' => ['ar']],
        ]);
        $w->approveMedia($this->user('maya'), $r, 'normal', null, $fadi->employee_id);
        $w->start($fadi, $r);

        $show = Livewire::actingAs($fadi)->test(Show::class, ['mediaRequest' => $r->fresh()])
            ->assertSee(__('media.check.title'))
            ->assertSee(__('media.check.logo'))
            ->set('link', 'https://drive.example.test/poster')
            ->set('sendChecks', ['text', 'logo'])
            ->call('sendResult')->assertHasErrors('sendChecks');
        $this->assertSame(S::InProgress, $r->fresh()->status);

        $show->set('link', 'https://drive.example.test/poster')->set('sendChecks', ['text', 'logo', 'facts', 'versions'])
            ->call('sendResult')->assertHasNoErrors()->assertSet('sendChecks', []);
        $this->assertSame(S::InternalReview, $r->fresh()->status);
    }

    private function user(string $who): User
    {
        return User::where('email', $who.'@example.test')->firstOrFail();
    }
}
