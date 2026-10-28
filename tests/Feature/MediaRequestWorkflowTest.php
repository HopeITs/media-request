<?php

namespace Tests\Feature;

use App\Enums\MediaRequestStatus as S;
use App\Livewire\MediaRequests\Show;
use App\Models\City;
use App\Models\MediaRequest;
use App\Models\Program;
use App\Models\User;
use App\Notifications\MediaRequestUpdated;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\HcPack;
use App\Support\MediaRequests\MediaAssistant;
use App\Support\MediaRequests\MediaNaming;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MediaRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    private int $programId;

    private User $requester;

    private User $programManager;

    private User $mediaManager;

    private User $designer;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->buildOrganisation();
    }

    public function test_full_path_from_request_to_archive(): void
    {
        $w = app(MediaRequestWorkflow::class);

        $r = $w->submit($this->requester, $this->certificateRequest());
        $this->assertSame(S::ProgramApproval, $r->status);
        $this->assertTrue($r->needs_program_approval);
        $this->assertSame('MR-001-'.now()->format('y').'-0001', $r->reference);
        Notification::assertSentTo($this->programManager, MediaRequestUpdated::class);

        $w->approveProgram($this->programManager, $r);
        $this->assertSame(S::MediaApproval, $r->status);
        $this->assertSame($this->programManager->employee_id, $r->program_approver_id);

        $w->approveMedia($this->mediaManager, $r, 'high', today()->addDays(5)->toDateString());
        $this->assertSame(S::ToClaim, $r->status);
        $this->assertSame('high', $r->priority);

        $w->claim($this->designer, $r);
        $this->assertSame(S::Assigned, $r->status);
        $this->assertSame($this->designer->employee_id, $r->assignee_id);

        $w->start($this->designer, $r);
        $w->shareDraft($this->designer, $r, 'https://drive.example.org/draft');
        $this->assertSame(S::InProgress, $r->status);

        // The first version goes to the media manager for the internal check.
        $w->sendResult($this->designer, $r, 'https://drive.example.org/v1');
        $this->assertSame(S::InternalReview, $r->status);

        $w->approveInternal($this->mediaManager, $r);
        $this->assertSame(S::RequesterReview, $r->status);
        $this->assertTrue($r->internal_ok);

        $w->requestChanges($this->requester, $r, 'Change the trainer name.');
        $this->assertSame(S::InProgress, $r->status);
        $this->assertSame(1, $r->revisions);

        // After the internal check passed once, revisions go straight to the requester.
        $w->sendResult($this->designer, $r, 'https://drive.example.org/v2');
        $this->assertSame(S::RequesterReview, $r->status);

        $w->approveResult($this->requester, $r);
        $this->assertSame(S::Delivery, $r->status);

        $w->deliver($this->designer, $r, 'https://drive.example.org/archive');
        $r->refresh();

        $this->assertSame(S::Delivered, $r->status);
        $this->assertSame('005DISC_ALP_Certificate_V2_'.now()->format('mdY'), $r->archive_name);
        $this->assertNotNull($r->delivered_at);

        $this->assertSame(
            ['submitted', 'pmApproved', 'mmApproved', 'claimed', 'started', 'draft', 'sentInt', 'intOk', 'changes', 'sentReq', 'reqOk', 'delivered'],
            Activity::query()->where('log_name', MediaRequest::LOG_NAME)->oldest('id')->pluck('description')->all(),
        );
    }

    public function test_program_manager_request_skips_program_approval(): void
    {
        $r = app(MediaRequestWorkflow::class)->submit($this->programManager, $this->certificateRequest());

        $this->assertFalse($r->needs_program_approval);
        $this->assertSame(S::MediaApproval, $r->status);
        $this->assertNotContains('pm', $r->steps());
    }

    public function test_media_manager_can_assign_directly_on_approval(): void
    {
        $w = app(MediaRequestWorkflow::class);
        $r = $w->submit($this->programManager, $this->certificateRequest());

        $w->approveMedia($this->mediaManager, $r, 'normal', null, $this->designer->employee_id);

        $this->assertSame(S::Assigned, $r->status);
        $this->assertSame($this->designer->employee_id, $r->assignee_id);
        Notification::assertSentTo($this->designer, MediaRequestUpdated::class);
    }

    public function test_requester_cannot_approve_their_own_request(): void
    {
        $r = app(MediaRequestWorkflow::class)->submit($this->requester, $this->certificateRequest());

        Livewire::actingAs($this->requester)
            ->test(Show::class, ['mediaRequest' => $r, 'embedded' => true])   // a requester sees it in the home-screen panel
            ->call('approveProgram')
            ->assertForbidden();

        $this->assertSame(S::ProgramApproval, $r->fresh()->status);
    }

    public function test_media_manager_cannot_skip_the_program_manager(): void
    {
        $r = app(MediaRequestWorkflow::class)->submit($this->requester, $this->certificateRequest());

        $this->expectException(AuthorizationException::class);

        app(MediaRequestWorkflow::class)->approveMedia($this->mediaManager, $r, 'normal');
    }

    public function test_outsider_cannot_open_the_request(): void
    {
        $r = app(MediaRequestWorkflow::class)->submit($this->requester, $this->certificateRequest());
        $outsider = $this->person('Outsider', $this->position('OTHER', 8, $this->otherProgram()));

        Livewire::actingAs($outsider)
            ->test(Show::class, ['mediaRequest' => $r])
            ->assertForbidden();
    }

    public function test_edit_logs_the_changed_fields(): void
    {
        $w = app(MediaRequestWorkflow::class);
        $r = $w->submit($this->requester, $this->certificateRequest());

        $w->update($this->requester, $r, [...$this->certificateRequest(), 'title' => 'Trainers', 'answers' => ['format' => 'cert', 'certNames' => 'names', 'langs' => ['ar', 'en'], 'alsoPrint' => 'no']]);

        $edit = Activity::query()->where('description', 'edited')->firstOrFail();
        $this->assertSame(['title', 'answers.certNames', 'answers.langs'], array_keys($edit->properties['changes']));
    }

    public function test_request_name_must_be_english(): void
    {
        try {
            app(MediaRequestWorkflow::class)->submit($this->requester, [...$this->certificateRequest(), 'title' => 'المتطوعين']);
            $this->fail('An Arabic request name was accepted.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
        }

        $r = app(MediaRequestWorkflow::class)->submit($this->requester, [...$this->certificateRequest(), 'title' => 'First-aid volunteers_2']);
        $this->assertSame('First-aid volunteers_2', $r->title);
    }

    public function test_missing_required_answer_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(MediaRequestWorkflow::class)->submit($this->requester, [...$this->certificateRequest(), 'answers' => ['format' => 'social']]);
    }

    public function test_pack_request_is_recorded_and_delivered_by_any_team_member(): void
    {
        $path = app(HcPack::class)->all()->first()['path'];
        $w = app(MediaRequestWorkflow::class);

        $r = $w->requestPack($this->requester, [$path, 'not/in/the/pack.png'], 'For the newsletter');

        $this->assertSame('000', $r->service);
        $this->assertSame([$path], $r->pack_files);
        $this->assertSame(S::Delivery, $r->status);
        $this->assertSame('team', $r->owner());
        $this->assertFalse($r->needs_program_approval);

        $w->deliver($this->designer, $r);

        $this->assertSame(S::Delivered, $r->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['log_name' => MediaRequest::LOG_NAME, 'description' => 'packDone']);
    }

    public function test_naming_follows_the_archive_convention(): void
    {
        $naming = app(MediaNaming::class);
        $program = Program::find($this->programId);
        $city = City::find($this->cityId);

        $this->assertSame('005DISC_ALP_Certificate', $naming->prefix($program, $city, $naming->itemCode('001', 'new', ['format' => 'cert'])));
        $this->assertSame('Flyer', $naming->itemCode('001', 'new', ['format' => 'brochure', 'brochureType' => 'flyer']));
        $this->assertSame('Photos', $naming->itemCode('002', 'shoot'));

        app(MediaRequestWorkflow::class)->submit($this->requester, $this->certificateRequest());
        $this->assertSame('MR-001-'.now()->format('y').'-0002', $naming->nextReference('001'));
        $this->assertSame('MR-002-'.now()->format('y').'-0001', $naming->nextReference('002'));
    }

    public function test_assistant_does_not_ask_what_the_text_already_says(): void
    {
        $result = app(MediaAssistant::class)->analyze(
            'لازمني شهادات لطلاب الديسك بحلب بكرا',
            Program::all(),
            City::all(),
        );

        $this->assertSame(['001', 'new'], [$result['service'], $result['type']]);
        $this->assertSame('cert', $result['answers']['format']);
        $this->assertSame($this->programId, $result['program_id']);
        $this->assertSame($this->cityId, $result['city_id']);
        $this->assertSame(today()->addDay()->toDateString(), $result['needed_by']);

        $asked = array_column($result['questions'], 'id');
        $this->assertNotContains('program', $asked);
        $this->assertNotContains('city', $asked);
        $this->assertNotContains('deadline', $asked);
    }

    public function test_assistant_asks_for_the_program_when_it_is_not_named(): void
    {
        $result = app(MediaAssistant::class)->analyze('I need a poster', Program::all(), City::all());

        $this->assertContains('program', array_column($result['questions'], 'id'));
    }

    public function test_nudge_is_once_a_day_and_never_to_yourself(): void
    {
        $w = app(MediaRequestWorkflow::class);
        $r = $w->submit($this->requester, $this->certificateRequest());

        $this->assertFalse($this->programManager->can('nudge', $r));

        $w->nudge($this->requester, $r);
        $this->assertNotNull($r->last_nudged_at);
        $this->assertFalse($this->requester->can('nudge', $r->fresh()));
    }

    public function test_every_media_request_livewire_action_is_classified(): void
    {
        $mapped = config('access-control.livewire');
        $exempt = config('access-control.livewire_exempt');

        foreach (['approveProgram', 'approveMedia', 'claim', 'deliver', 'comment'] as $method) {
            $this->assertArrayHasKey($method, $mapped['media-requests.show']);
        }
        $this->assertContains('next', $exempt['media-requests.request-wizard']);
        $this->assertSame('media-requests.export', $mapped['media-requests.log']['exportCsv']);
    }

    // ------------------------------------------------------------------
    // Fixture: one program (005 DISC) in Aleppo with a program manager and a
    // team member, and the Communication (CT) department with its manager
    // and a designer.
    // ------------------------------------------------------------------

    public function test_a_design_can_ask_for_printing_in_the_same_request(): void
    {
        $w = app(MediaRequestWorkflow::class);
        $brochure = fn (array $print) => [...$this->certificateRequest(), 'title' => 'Programme',
            'answers' => ['format' => 'brochure', 'brochureType' => 'trifold', 'langs' => ['ar'], ...$print]];

        // Printing is asked about for anything that ends up on paper.
        try {
            $w->submit($this->requester, $brochure([]));
            $this->fail('alsoPrint should be required for a brochure');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('answers.alsoPrint', $e->errors());
        }

        // Yes: how many and the budget are needed.
        try {
            $w->submit($this->requester, $brochure(['alsoPrint' => 'yes']));
            $this->fail('quantity and budget should be required');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('answers.printQty', $e->errors());
            $this->assertArrayHasKey('answers.budget', $e->errors());
        }

        $known = $w->submit($this->requester, $brochure(['alsoPrint' => 'yes', 'printQty' => 300, 'budget' => 'known', 'budgetAmount' => '40 USD']));
        $this->assertTrue($known->wantsPrint());
        $this->assertFalse($known->needsQuote());
        $this->assertTrue($known->needsProduction());   // design, then printing, then delivery
        $this->assertContains('production', $known->steps());

        $quote = $w->submit($this->requester, $brochure(['alsoPrint' => 'yes', 'printQty' => 300, 'budget' => 'quote']));
        $this->assertTrue($quote->needsQuote());

        // No printing: a design only.
        $designOnly = $w->submit($this->requester, $brochure(['alsoPrint' => 'no']));
        $this->assertFalse($designOnly->needsProduction());
        $this->assertNotContains('production', $designOnly->steps());

        // Social posts never ask about printing.
        $post = $w->submit($this->requester, [...$this->certificateRequest(), 'title' => 'Post',
            'answers' => ['format' => 'social', 'socialKind' => ['post'], 'langs' => ['ar']]]);
        $this->assertFalse($post->wantsPrint());
    }

    private function certificateRequest(): array
    {
        return [
            'service' => '001',
            'type' => 'new',
            'program_id' => $this->programId,
            'city_id' => $this->cityId,
            'title' => 'Volunteers',
            'needed_by' => today()->addWeekdays(8)->toDateString(),
            'answers' => ['format' => 'cert', 'certNames' => 'blank', 'langs' => ['ar'], 'alsoPrint' => 'no'],
            'description' => 'Certificates for the volunteer training.',
        ];
    }

    private function buildOrganisation(): void
    {
        $this->cityId = DB::table('cities')->insertGetId([
            'name_en' => 'Aleppo', 'name_ar' => 'حلب', 'slug' => 'ALP', 'code' => '001',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->programId = DB::table('programs')->insertGetId([
            'name' => 'Development Impact and Support Center', 'code' => '005', 'slug' => 'DISC',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->requester = $this->person('Requester', $this->position('PRG', 8, $this->programId));
        $this->programManager = $this->person('Program', $this->position('PRG', 1, $this->programId), 'manager');
        $this->mediaManager = $this->person('Media', $this->position('CT', 5, $this->programId), 'manager');
        $this->designer = $this->person('Designer', $this->position('CT', 8, $this->programId));

        app(MediaTeam::class)->flush();
    }

    private function otherProgram(): int
    {
        return DB::table('programs')->insertGetId([
            'name' => 'Study Zone', 'code' => '006', 'slug' => 'SZ',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Standalone version of the HR fixture's "position": the same department
     * and grade rules, turned into this app's media_role and program_managers.
     *
     * @return array{media_role: ?string, programs: array<int, int>}
     */
    private function position(string $department, int $grade, int $programId): array
    {
        if ($department === 'CT') {
            return ['media_role' => in_array($grade, config('media-requests.media_manager_grades'), true) ? 'manager' : 'member', 'programs' => []];
        }

        return ['media_role' => null, 'programs' => in_array($grade, config('media-requests.program_manager_grades'), true) ? [$programId] : []];
    }

    private function person(string $name, array $position, string $role = 'employee'): User
    {
        $employeeId = DB::table('employees')->insertGetId([
            'city_id' => $this->cityId, 'first_name_en' => $name, 'first_name_ar' => $name,
            'last_name_en' => 'Test', 'last_name_ar' => 'Test', 'username' => strtolower($name),
            'media_role' => $position['media_role'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($position['programs'] as $programId) {
            DB::table('program_managers')->insert(['employee_id' => $employeeId, 'program_id' => $programId]);
        }

        return User::query()->create([
            'name' => $name,
            'email' => strtolower($name).'@example.test',
            'password' => 'password',
            'role' => $role,
            'employee_id' => $employeeId,
        ])->fresh();
    }
}