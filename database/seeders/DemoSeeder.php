<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Employee;
use App\Models\MediaProofread;
use App\Models\Program;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Database\Seeder;

/**
 * Extra sample data for trying every screen (local preview only), on top of
 * DatabaseSeeder: two more requesters, and some twenty requests in every kind
 * and stage — waiting, in work, in review, at the printer, waiting for an
 * answer, on hold, delivered, rejected and cancelled — with comments.
 *
 * Everything goes through MediaRequestWorkflow, so the history, names and
 * notifications are what real use produces. Run it again and it adds more.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private MediaRequestWorkflow $w;

    /** @var array<string, User> */
    private array $u = [];

    public function run(): void
    {
        $this->w = app(MediaRequestWorkflow::class);
        foreach (['rami', 'lina', 'maya', 'fadi', 'sami', 'admin'] as $key) {
            $this->u[$key] = User::where('email', $key.'@example.test')->firstOrFail();
        }
        $this->u['nour'] = $this->person('nour', 'Nour', 'نور', 'HMS');
        $this->u['omar'] = $this->person('omar', 'Omar', 'عمر', 'DMS');
        app(MediaTeam::class)->flush();

        $this->requests();
        $this->proofreads();
    }

    private function requests(): void
    {
        [$u, $w] = [$this->u, $this->w];
        $fadi = $u['fadi']->employee_id;
        $sami = $u['sami']->employee_id;
        $maya = $u['maya']->employee_id;

        // Waiting for the program manager (Lina manages DISC and MP).
        $this->ask('rami', '001', 'new', 'DISC', 'ALP', 'Annual report', 12, ['format' => 'newsletter', 'newsMedium' => ['print'], 'pages' => 24, 'langs' => ['ar', 'en'],
            'alsoPrint' => 'yes', 'printQty' => 200, 'printColor' => 'color', 'budget' => 'quote'], 'التقرير السنوي لمركز الديسك، 24 صفحة، مع طباعة 200 نسخة.');
        $this->ask('rami', '003', 'shoot', 'MP', 'ALP', 'Bazaar', 9, ['videoKind' => 'event', 'eventDate' => $this->day(8), 'place' => 'MP market', 'children' => 'no',
            'duration' => '60s', 'orientation' => 'vertical']);

        // Waiting for the media manager.
        $this->ask('nour', '001', 'edit', 'SZ', 'HMS', 'Timetable', 3, ['editWhat' => ['dates', 'text']], 'تعديل مواعيد الدورات في جدول الشهر.');
        $this->ask('omar', '002', 'shoot', 'LEAP', 'DMS', 'Workshop', 10, ['coverage' => 'session', 'eventDate' => $this->day(6), 'place' => 'LEAP Damascus', 'children' => 'yes', 'consent' => true]);

        // Approved, nobody has taken them yet.
        $r = $this->ask('omar', '001', 'new', 'LEAP', 'DMS', 'Open day', 8, ['format' => 'poster', 'versions' => ['print', 'story'], 'posterSize' => 'A2', 'langs' => ['ar'],
            'alsoPrint' => 'yes', 'printQty' => 30, 'printColor' => 'color', 'budget' => 'known', 'budgetAmount' => '150 USD']);
        $w->approveMedia($u['maya'], $r, 'normal');
        $r = $this->ask('nour', '003', 'export', 'SZ', 'HMS', 'Testimonials', 12, ['editKind' => 'highlights', 'duration' => '60s', 'orientation' => 'vertical']);
        $w->approveMedia($u['maya'], $r, 'normal');

        // Taken, not started.
        $r = $this->ask('rami', '001', 'new', 'MP', 'ALP', 'Price list', 6, ['format' => 'brochure', 'brochureType' => 'trifold', 'brochureSize' => 'A4', 'langs' => ['ar'], 'alsoPrint' => 'no']);
        $w->approveProgram($u['lina'], $r);
        $w->approveMedia($u['maya'], $r, 'normal', null, $fadi);

        // In work.
        $r = $this->ask('omar', '001', 'new', 'LEAP', 'DMS', 'Mentors', 7, ['format' => 'cert', 'certNames' => 'names', 'langs' => ['ar', 'en'], 'alsoPrint' => 'no']);
        $w->approveMedia($u['maya'], $r, 'high', null, $fadi);
        $w->start($u['fadi'], $r);
        $w->comment($u['fadi'], $r, 'وصلتني قائمة الأسماء، شكراً. أرسل النسخة الأولى غداً.');

        $r = $this->ask('nour', '003', 'export', 'SZ', 'HMS', 'Promo', 9, ['editKind' => 'full', 'duration' => '3m', 'orientation' => 'horizontal']);
        $w->approveMedia($u['maya'], $r, 'normal', null, $sami);
        $w->start($u['sami'], $r);

        // Waiting for an answer from the requester.
        $r = $this->ask('rami', '001', 'new', 'DISC', 'ALP', 'Roll up', 8, ['format' => 'rollup', 'rollupSize' => 'r85', 'langs' => ['ar'], 'alsoPrint' => 'yes', 'printQty' => 2,
            'printColor' => 'color', 'budget' => 'quote']);
        $w->approveProgram($u['lina'], $r);
        $w->approveMedia($u['maya'], $r, 'normal', null, $fadi);
        $w->start($u['fadi'], $r);
        $w->askInfo($u['fadi'], $r, 'هل تريد شعار الممول على الرول أب؟ وأرسل لي النص النهائي من فضلك.');

        // With the media manager to check.
        $r = $this->ask('omar', '002', 'export', 'LEAP', 'DMS', 'Selection', 4, ['deliverWhat' => 'retouch', 'count' => 20]);
        $w->approveMedia($u['maya'], $r, 'normal', null, $sami);
        $w->start($u['sami'], $r);
        $w->sendResult($u['sami'], $r, 'https://drive.google.com/drive/folders/demo-photos-v1', '20 صورة بعد التعديل');

        // With the requester to review.
        $r = $this->ask('nour', '001', 'new', 'SZ', 'HMS', 'Summer camp', 6, ['format' => 'social', 'socialKind' => ['post', 'carousel'], 'slides' => 5, 'platforms' => ['instagram'], 'langs' => ['ar']]);
        $w->approveMedia($u['maya'], $r, 'normal', null, $fadi);
        $w->start($u['fadi'], $r);
        $w->sendResult($u['fadi'], $r, 'https://drive.google.com/drive/folders/demo-camp-v1');
        $w->approveInternal($u['maya'], $r);

        // At the printer.
        $r = $this->ask('rami', '001', 'print', 'DISC', 'ALP', 'Flyers', 8, ['printItem' => 'flyer', 'qty' => 500, 'paperSize' => 'A5', 'color' => 'color', 'paper' => 'standard', 'sides' => 'two']);
        $w->approveProgram($u['lina'], $r);
        $w->approveMedia($u['maya'], $r, 'normal', null, $fadi);
        $w->start($u['fadi'], $r);
        $w->sendResult($u['fadi'], $r, 'https://drive.google.com/drive/folders/demo-flyer-print');
        $w->approveInternal($u['maya'], $r);
        $w->approveResult($u['rami'], $r);

        // On hold.
        $r = $this->ask('omar', '001', 'production', 'LEAP', 'DMS', 'Sign', 12, ['kind' => 'signage', 'qty' => 1, 'size' => '200 x 80 cm']);
        $w->approveMedia($u['maya'], $r, 'low', null, $maya);
        $w->hold($u['maya'], $r, 'ننتظر موافقة الإدارة على مكان اللوحة.');

        // Delivered.
        foreach ([['nour', 'SZ', 'HMS', 'Welcome'], ['omar', 'LEAP', 'DMS', 'Banner']] as [$who, $prog, $city, $title]) {
            $r = $this->ask($who, '001', 'new', $prog, $city, $title, 5, ['format' => 'social', 'socialKind' => ['post'], 'platforms' => ['facebook'], 'langs' => ['ar']]);
            $w->approveMedia($u['maya'], $r, 'normal', null, $fadi);
            $w->start($u['fadi'], $r);
            $w->sendResult($u['fadi'], $r, 'https://drive.google.com/drive/folders/demo-'.strtolower($title));
            $w->approveInternal($u['maya'], $r);
            $w->approveResult($u[$who], $r);
            $w->deliver($u['fadi'], $r, 'https://drive.google.com/drive/folders/demo-archive-'.strtolower($title));
        }

        // Rejected and cancelled.
        $r = $this->ask('nour', '001', 'new', 'SZ', 'HMS', 'Mug', 10, ['format' => 'other', 'customSize' => 'Mug wrap', 'langs' => ['ar']]);
        $w->reject($u['maya'], $r, 'موجود قالب جاهز للكاسات في HC_PACK، استعمله مباشرة.');
        $r = $this->ask('omar', '002', 'shoot', 'LEAP', 'DMS', 'Visit', 7, ['coverage' => 'visit', 'eventDate' => $this->day(5), 'place' => 'School', 'children' => 'no']);
        $w->cancel($u['omar'], $r, 'تأجلت الزيارة.');
    }

    /** A few saved proofreading checks, so "My checks" is not empty. */
    private function proofreads(): void
    {
        foreach (['rami', 'maya'] as $who) {
            MediaProofread::create([
                'user_id' => $this->u[$who]->id,
                'source' => 'text',
                'original' => 'يعلن مركز ديسك : عن اختياره لمجموعه من الشباب التي تعرف التصميم',
                'corrected' => 'يعلن مركز ديسك عن اختياره لمجموعة من الشباب الذين يعرفون التصميم.',
                'result' => [
                    'changes' => [
                        ['before' => 'مجموعه', 'after' => 'مجموعة', 'note' => 'تاء مربوطة'],
                        ['before' => 'التي تعرف', 'after' => 'الذين يعرفون', 'note' => 'جمع المذكر العاقل'],
                    ],
                    'suggestions' => ['يمكن قول «المهتمين بالتصميم».'], 'summary' => 'تصحيحان وعلامة ترقيم.',
                    'language' => 'ar', 'ai' => true, 'aiError' => null, 'localFixes' => ['spaceBefore'],
                ],
            ]);
        }
    }

    private function ask(string $who, string $service, string $type, string $program, string $city, string $title, int $days, array $answers, ?string $description = null)
    {
        return $this->w->submit($this->u[$who], [
            'service' => $service, 'type' => $type,
            'program_id' => Program::where('slug', $program)->value('id'),
            'city_id' => City::where('slug', $city)->value('id'),
            'title' => $title, 'needed_by' => $this->day($days),
            'rush_reason' => 'Demo data',   // only kept when the date is earlier than the team can do it
            'answers' => $answers, 'description' => $description,
        ]);
    }

    /** $n working days from today: never a weekend. */
    private function day(int $n): string
    {
        return today()->addWeekdays($n)->toDateString();
    }

    private function person(string $key, string $en, string $ar, string $city): User
    {
        $employee = Employee::firstOrCreate(['username' => $key], [
            'first_name_en' => $en, 'first_name_ar' => $ar, 'city_id' => City::where('slug', $city)->value('id'),
        ]);

        return User::firstOrCreate(['email' => $key.'@example.test'], [
            'name' => $en, 'password' => 'password', 'role' => 'employee', 'employee_id' => $employee->id,
        ]);
    }
}
