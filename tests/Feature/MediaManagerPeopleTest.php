<?php

namespace Tests\Feature;

use App\Livewire\People\Index;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** The media manager looks after the accounts, short of the administrators', and sees who uses the app. */
class MediaManagerPeopleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** The team list makes every account 'employee': the screen offered Approve, then the gate answered 403. */
    public function test_the_media_manager_on_an_employee_account_can_approve(): void
    {
        $maya = $this->user('maya');
        $maya->update(['role' => 'employee']);
        foreach (['media-requests.approve', 'media-requests.manage', 'media-requests.view-log', 'media-requests.export'] as $permission) {
            $this->assertTrue($maya->fresh()->hasActivePermission($permission), $permission);
        }

        // A plain team member stays as they were, and so does a manager who has left.
        $fadi = $this->user('fadi');
        $this->assertSame('employee', $fadi->role);
        $this->assertFalse($fadi->hasActivePermission('media-requests.approve'));
        $this->assertFalse($fadi->hasActivePermission('media-requests.manage'));

        $maya->profile->update(['is_active' => false]);
        $this->assertFalse($maya->fresh()->hasActivePermission('media-requests.approve'));
    }

    public function test_the_manager_of_a_program_on_an_employee_account_can_approve(): void
    {
        $fadi = $this->user('fadi');
        $fadi->profile->managedPrograms()->sync([\App\Models\Program::query()->value('id')]);

        $this->assertTrue($fadi->fresh()->hasActivePermission('media-requests.approve'));
        $this->assertFalse($fadi->fresh()->hasActivePermission('media-requests.manage'));
    }

    public function test_the_media_manager_adds_people_and_gives_roles_but_not_an_administrators(): void
    {
        $maya = $this->user('maya');
        $this->actingAs($maya)->get(route('people.index'))->assertOk();
        $this->actingAs($this->user('fadi'))->get(route('people.index'))->assertForbidden();   // a team member does not

        Livewire::actingAs($maya)->test(Index::class)
            ->set('quickEmail', 'new.person@example.test')->set('quickName', 'Nour')->set('quickRole', 'staff')
            ->call('quickAdd')->assertHasNoErrors();
        $this->assertTrue(User::where('email', 'new.person@example.test')->exists());

        // A role only an administrator gives.
        $rami = $this->user('rami')->profile;
        Livewire::actingAs($maya)->test(Index::class)->call('edit', $rami->id)->set('role', 'super-admin')
            ->call('save')->assertHasErrors('role');
        $this->assertSame('employee', $this->user('rami')->role);

        // An administrator's account is not theirs to change.
        $admin = $this->user('admin')->profile;
        Livewire::actingAs($maya)->test(Index::class)->assertDontSeeHtml('wire:click="edit('.$admin->id.')"')
            ->call('edit', $admin->id)->assertForbidden();
        Livewire::actingAs($maya)->test(Index::class)->call('toggleActive', $admin->id)->assertForbidden();

        // An administrator still gives any role.
        Livewire::actingAs($this->user('admin'))->test(Index::class)->call('edit', $rami->id)->set('role', 'general-manager')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('general-manager', $this->user('rami')->role);
    }

    public function test_who_is_online_and_who_never_signed_in(): void
    {
        $maya = $this->user('maya');
        DB::table('users')->where('email', 'fadi@example.test')->update(['last_seen_at' => now()->subMinute()]);
        DB::table('users')->where('email', 'sami@example.test')->update(['last_seen_at' => now()->subDays(3)]);

        $page = Livewire::actingAs($maya)->test(Index::class)->set('show', 'online');
        $page->assertSee($this->user('fadi')->profile->nameForLocale(app()->getLocale()))
            ->assertDontSee($this->user('sami')->profile->nameForLocale(app()->getLocale()));

        $this->assertTrue($this->user('fadi')->isOnline());
        $this->assertFalse($this->user('sami')->isOnline());

        // Using the app notes it.
        $this->actingAs($this->user('rami'))->get(route('media-requests.home'));
        $this->assertNotNull($this->user('rami')->last_seen_at);
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@example.test')->firstOrFail();
    }
}
