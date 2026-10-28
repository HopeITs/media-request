<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Employee;
use App\Models\Program;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\HcPack;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Database\Seeder;

/**
 * Programs and governorates as in the HR app, a handful of sample people
 * (password "password", local preview only), and requests at different stages.
 * The requests are made through MediaRequestWorkflow, so their history,
 * notifications and names are exactly what real use produces.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->places();

        // On a real server only the programs and governorates: no sample people (their password is
        // "password"). Create the first administrator there with: php artisan media:admin
        if (app()->environment('production')) {
            return;
        }

        $people = $this->people();

        if (app()->environment('local')) {
            $this->sampleRequests($people);
        }
    }

    private function places(): void
    {
        foreach ([
            ['001', 'HC', 'Christian Hope Center'], ['002', 'MP', 'Micro Projects'], ['003', 'RLF', 'Relief'],
            ['004', 'S2S', 'School to School'], ['005', 'DISC', 'Development Impact and Support Center'],
            ['006', 'SZ', 'Study Zone'], ['008', 'CU', 'Children University'], ['009', 'CYG', null], ['010', 'LEAP', null],
        ] as [$code, $slug, $name]) {
            Program::firstOrCreate(['slug' => $slug], ['code' => $code, 'name' => $name]);
        }

        foreach ([
            ['000', 'SYR', 'Syria (national)', 'سوريا (على مستوى وطني)'], ['001', 'ALP', 'Aleppo', 'حلب'], ['002', 'HMS', 'Homs', 'حمص'],
            ['003', 'DMS', 'Damascus', 'دمشق'], ['004', 'LAT', 'Latakia', 'اللاذقية'], ['005', 'TAR', 'Tartus', 'طرطوس'],
            ['006', 'HAM', 'Hama', 'حماة'], ['007', 'DAR', 'Daraa', 'درعا'], ['008', 'SWD', 'Suwayda', 'السويداء'],
            ['009', 'QNT', 'Quneitra', 'القنيطرة'], ['010', 'RQA', 'Raqqa', 'الرقة'], ['011', 'DZR', 'Deir ez-Zor', 'دير الزور'],
            ['012', 'HSK', 'Hasakah', 'الحسكة'], ['013', 'IDL', 'Idlib', 'إدلب'], ['014', 'RID', 'Damascus Countryside', 'ريف دمشق'],
        ] as [$code, $slug, $en, $ar]) {
            City::firstOrCreate(['slug' => $slug], ['code' => $code, 'name_en' => $en, 'name_ar' => $ar]);
        }
    }

    /** @return array<string, User> */
    private function people(): array
    {
        $alp = City::where('slug', 'ALP')->value('id');
        $out = [];

        foreach ([
            'admin' => ['Admin', 'المسؤول', 'super-admin', null, [], null],
            'rami' => ['Rami', 'رامي', 'employee', null, [], null],
            'lina' => ['Lina', 'لينا', 'manager', null, ['DISC', 'MP'], null],
            'maya' => ['Maya', 'مايا', 'manager', 'manager', [], 'Communication manager / Designer / Photographer'],
            'fadi' => ['Fadi', 'فادي', 'employee', 'member', [], 'Communication team member / Designer'],
            'sami' => ['Sami', 'سامي', 'employee', 'member', [], 'Communication team member / Videographer / Editor'],
        ] as $key => [$en, $ar, $role, $mediaRole, $programs, $title]) {
            $employee = Employee::firstOrCreate(['username' => $key], [
                'first_name_en' => $en, 'first_name_ar' => $ar, 'city_id' => $alp, 'media_role' => $mediaRole, 'position_title' => $title,
            ]);
            $employee->managedPrograms()->sync(Program::whereIn('slug', $programs)->pluck('id'));

            $out[$key] = User::firstOrCreate(['email' => $key.'@example.test'], [
                'name' => $en, 'password' => 'password', 'role' => $role, 'employee_id' => $employee->id,
            ]);
        }

        app(MediaTeam::class)->flush();

        return $out;
    }

    /** @param  array<string, User>  $u */
    private function sampleRequests(array $u): void
    {
        if (\App\Models\MediaRequest::exists()) {
            return;
        }

        $w = app(MediaRequestWorkflow::class);
        $disc = Program::where('slug', 'DISC')->value('id');
        $mp = Program::where('slug', 'MP')->value('id');
        $sz = Program::where('slug', 'SZ')->value('id');
        $alp = City::where('slug', 'ALP')->value('id');
        $hms = City::where('slug', 'HMS')->value('id');
        $in = fn (int $days) => today()->addWeekdays($days)->toDateString();   // working days: never a weekend

        // 1. Waiting for the program manager.
        $w->submit($u['rami'], [
            'service' => '001', 'type' => 'new', 'program_id' => $disc, 'city_id' => $alp, 'title' => 'Volunteers',
            'needed_by' => $in(10), 'answers' => ['format' => 'cert', 'certNames' => 'names', 'langs' => ['ar', 'en'], 'alsoPrint' => 'no'],
            'description' => 'شهادات لمتطوعي دورة الإسعافات الأولية، 25 اسم.',
        ]);

        // 2. Approved and waiting for someone to take it.
        $poster = $w->submit($u['rami'], [
            'service' => '001', 'type' => 'new', 'program_id' => $mp, 'city_id' => $alp, 'title' => 'Exhibition',
            'needed_by' => $in(14), 'answers' => ['format' => 'poster', 'versions' => ['print', 'square', 'story'], 'posterSize' => 'A3', 'langs' => ['ar']],
            'description' => 'بوستر لمعرض المشاريع الصغيرة.',
        ]);
        $w->approveProgram($u['lina'], $poster);
        $w->approveMedia($u['maya'], $poster, 'normal', $in(12));

        // 3. A program manager's own request: straight to the media manager, then assigned and started.
        $shoot = $w->submit($u['lina'], [
            'service' => '002', 'type' => 'shoot', 'program_id' => $disc, 'city_id' => $alp, 'title' => 'Graduation',
            'needed_by' => $in(6), 'answers' => ['coverage' => 'event', 'eventDate' => $in(5), 'place' => 'DISC hall', 'children' => 'no', 'usage' => ['social', 'report'], 'reportFor' => 'GIZ'],
        ]);
        $w->approveMedia($u['maya'], $shoot, 'high', $in(6), $u['sami']->employee_id);
        $w->start($u['sami'], $shoot);

        // 4. Checked by the media manager, now with the requester for review.
        $post = $w->submit($u['rami'], [
            'service' => '001', 'type' => 'new', 'program_id' => $sz, 'city_id' => $hms, 'title' => 'English courses',
            'needed_by' => $in(7), 'answers' => ['format' => 'social', 'socialKind' => ['post', 'story'], 'platforms' => ['facebook', 'instagram'], 'langs' => ['ar']],
        ]);
        $w->approveMedia($u['maya'], $post, 'normal');
        $w->claim($u['fadi'], $post);
        $w->start($u['fadi'], $post);
        $w->sendResult($u['fadi'], $post, 'https://drive.google.com/drive/folders/sample-v1', 'نسخة أولى');
        $w->approveInternal($u['maya'], $post);
        $w->comment($u['rami'], $post, 'ممكن نكبّر الشعار شوي؟');

        // 5. A ready file from HC_PACK, waiting for any team member to send it.
        $pack = app(HcPack::class)->search('شعار الديسك', null, Program::all())->take(2)->pluck('path')->all();
        if ($pack !== []) {
            $w->requestPack($u['rami'], $pack, 'للنشرة الشهرية');
        }

        // 6. Delivered and archived.
        $done = $w->submit($u['rami'], [
            'service' => '001', 'type' => 'new', 'program_id' => $disc, 'city_id' => $alp, 'title' => 'Trainers',
            'needed_by' => $in(3), 'rush_reason' => 'التخريج يوم الخميس', 'answers' => ['format' => 'id', 'idMaterial' => 'plastic', 'idFor' => "Ahmad - Trainer\nNour - Trainer", 'langs' => ['ar', 'en'], 'alsoPrint' => 'no'],
        ]);
        $w->approveProgram($u['lina'], $done);
        $w->approveMedia($u['maya'], $done, 'high', null, $u['fadi']->employee_id);
        $w->start($u['fadi'], $done);
        $w->sendResult($u['fadi'], $done, 'https://drive.google.com/drive/folders/sample-id-v1');
        $w->approveInternal($u['maya'], $done);
        $w->approveResult($u['rami'], $done);
        $w->deliver($u['fadi'], $done, 'https://drive.google.com/drive/folders/sample-archive');
    }
}
