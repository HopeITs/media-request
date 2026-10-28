<?php

namespace Tests\Unit;

use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\WorkCalendar;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The media team works Monday to Friday, 9 to 5; the market and print shops
 * close on Friday. Deadlines count only those days.
 * (2026-10-26 is a Monday, 2026-10-30 a Friday.)
 */
class WorkCalendarTest extends TestCase
{
    private function at(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Damascus');
    }

    private function earliest(string $time, string $service, string $type, array $answers = []): string
    {
        return app(WorkCalendar::class)->earliest($service, $type, $answers, $this->at($time))->format('D Y-m-d');
    }

    public function test_weekends_do_not_count(): void
    {
        // A new design takes 4 working days.
        $this->assertSame('Thu 2026-10-29', $this->earliest('2026-10-26 10:00', '001', 'new'));
        // Friday 16:30: the team starts on Monday.
        $this->assertSame('Thu 2026-11-05', $this->earliest('2026-10-30 16:30', '001', 'new'));
        // Saturday: also starts on Monday.
        $this->assertSame('Thu 2026-11-05', $this->earliest('2026-10-31 11:00', '001', 'new'));
    }

    public function test_a_small_edit_takes_a_day(): void
    {
        $this->assertSame('Fri 2026-10-30', $this->earliest('2026-10-30 10:00', '001', 'edit'));
        $this->assertSame('Mon 2026-11-02', $this->earliest('2026-10-30 16:30', '001', 'edit'));
    }

    public function test_outside_work_skips_friday_too(): void
    {
        // Print an existing design: a day to prepare (Friday), then two print-shop days, never Friday or the weekend.
        $this->assertSame('Tue 2026-11-03', $this->earliest('2026-10-29 15:00', '001', 'print'));
        // A new design that is also printed: design Mon to Thu, then printing Mon and Tue.
        $this->assertSame('Tue 2026-11-03', $this->earliest('2026-10-26 10:00', '001', 'new', ['alsoPrint' => 'yes']));
        $this->assertTrue(app(WorkCalendar::class)->needsOutside('001', 'new', ['alsoPrint' => 'yes']));
        $this->assertFalse(app(WorkCalendar::class)->needsOutside('001', 'new'));
    }

    public function test_earlier_than_the_team_can_do_it_is_a_rush(): void
    {
        Carbon::setTestNow($this->at('2026-10-30 16:30'));   // Friday afternoon
        $w = app(MediaRequestWorkflow::class);

        $this->assertTrue($w->isRush('2026-11-03', '001', 'new'));     // Tuesday: too soon for a new design
        $this->assertFalse($w->isRush('2026-11-05', '001', 'new'));    // Thursday: fine
        $this->assertFalse($w->isRush('2026-11-02', '001', 'edit'));   // Monday: fine for a small edit

        Carbon::setTestNow();
    }

    public function test_unknown_types_have_no_plan(): void
    {
        $this->assertNull(app(WorkCalendar::class)->earliest('999', 'new'));
    }
}
