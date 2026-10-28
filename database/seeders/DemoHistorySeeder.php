<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Program;
use App\Models\User;
use App\Services\MediaRequestWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Past work for the statistics page (local preview only): requests delivered
 * early, on the day and late over the last weeks, some sent back for changes
 * first. Run by DemoSeeder, or alone:
 *
 *   php artisan db:seed --class=DemoHistorySeeder
 */
class DemoHistorySeeder extends Seeder
{
    public function run(): void
    {
        $w = app(MediaRequestWorkflow::class);
        $u = fn (string $key) => User::where('email', $key.'@example.test')->firstOrFail();
        $people = ['rami' => 'DISC', 'nour' => 'SZ', 'omar' => 'LEAP'];
        $cities = ['DISC' => 'ALP', 'SZ' => 'HMS', 'LEAP' => 'DMS'];

        // [who, service, type, answers, assignee, working days late (+) or early (-), sent back?, weeks ago]
        $cases = [
            ['omar', '001', 'new', ['format' => 'poster', 'versions' => ['square'], 'langs' => ['ar']], 'fadi', -1, false, 5],
            ['nour', '001', 'edit', ['editWhat' => ['text']], 'fadi', 0, false, 5],
            ['rami', '001', 'new', ['format' => 'cert', 'certNames' => 'blank', 'langs' => ['ar'], 'alsoPrint' => 'no'], 'fadi', 1, true, 4],
            ['omar', '003', 'export', ['editKind' => 'highlights', 'duration' => '60s', 'orientation' => 'vertical'], 'sami', 0, true, 4],
            ['nour', '002', 'export', ['deliverWhat' => 'select'], 'maya', -2, false, 3],
            ['rami', '001', 'new', ['format' => 'social', 'socialKind' => ['post'], 'langs' => ['ar']], 'fadi', 0, false, 3],
            ['omar', '003', 'shoot', ['videoKind' => 'event', 'eventDate' => today()->addWeekdays(3)->toDateString(), 'place' => 'LEAP', 'children' => 'no', 'duration' => '30s', 'orientation' => 'vertical'], 'sami', 2, false, 2],
            ['nour', '001', 'new', ['format' => 'brochure', 'brochureType' => 'bifold', 'langs' => ['ar'], 'alsoPrint' => 'no'], 'fadi', -1, true, 2],
            ['rami', '002', 'shoot', ['coverage' => 'event', 'eventDate' => today()->addWeekdays(3)->toDateString(), 'place' => 'DISC', 'children' => 'no'], 'maya', 0, false, 1],
            ['omar', '001', 'new', ['format' => 'invite', 'inviteMedium' => 'digital', 'langs' => ['ar', 'en']], 'fadi', -1, false, 1],
        ];

        foreach ($cases as $i => [$who, $service, $type, $answers, $assignee, $off, $sentBack, $weeksAgo]) {
            $program = $people[$who];
            $r = $w->submit($u($who), [
                'service' => $service, 'type' => $type,
                'program_id' => Program::where('slug', $program)->value('id'),
                'city_id' => City::where('slug', $cities[$program])->value('id'),
                'title' => 'Past '.($i + 1), 'needed_by' => today()->addWeekdays(10)->toDateString(),
                'answers' => $answers,
            ]);
            if ($r->status === \App\Enums\MediaRequestStatus::ProgramApproval) {
                $w->approveProgram($u('lina'), $r);
            }
            $w->approveMedia($u('maya'), $r, 'normal', null, $u($assignee)->employee_id);
            $w->start($u($assignee), $r);
            $w->sendResult($u($assignee), $r, 'https://drive.google.com/drive/folders/demo-past-'.($i + 1));
            if ($sentBack) {
                // Sent back by the media manager (at the check), or by the requester (after the check).
                if ($i % 2) {
                    $w->returnToDesigner($u('maya'), $r, 'الشعار غير واضح، كبّره قليلاً.');
                } else {
                    $w->approveInternal($u('maya'), $r);
                    $w->requestChanges($u($who), $r->refresh(), 'عدّل التاريخ من فضلك.');
                }
                $w->sendResult($u($assignee), $r->refresh(), 'https://drive.google.com/drive/folders/demo-past-'.($i + 1).'-v2');
            }
            $r->refresh();
            if ($r->status === \App\Enums\MediaRequestStatus::InternalReview) {
                $w->approveInternal($u('maya'), $r);
            }
            if ($r->refresh()->status === \App\Enums\MediaRequestStatus::RequesterReview) {
                $w->approveResult($u($who), $r);
            }
            $r->refresh();
            if ($r->status === \App\Enums\MediaRequestStatus::Production) {
                $w->markReady($u($assignee), $r);
            }
            $w->deliver($u($assignee), $r->refresh(), 'https://drive.google.com/drive/folders/demo-past-archive-'.($i + 1));

            // Move it into the past: sent weeks ago, delivered early, on the day or late.
            $sent = today()->subWeeks($weeksAgo)->startOfWeek()->addDays(1)->setTime(10, 0);
            $due = $sent->copy()->addWeekdays(4);
            $done = $off >= 0 ? $due->copy()->addWeekdays($off) : $due->copy()->subWeekdays(-$off);
            $r->forceFill(['created_at' => $sent, 'needed_by' => $due->toDateString(), 'delivered_at' => $done->setTime(15, 0), 'status_changed_at' => $done])->saveQuietly();
            \Spatie\Activitylog\Models\Activity::where('subject_type', $r->getMorphClass())->where('subject_id', $r->id)
                ->update(['created_at' => Carbon::parse($sent)->addHours(2)]);
        }
    }
}
