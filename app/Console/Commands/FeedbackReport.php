<?php

namespace App\Console\Commands;

use App\Models\BetaFeedback;
use App\Support\BetaFeedback as Words;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The feedback sent while the app is being tried out, for whoever works on the app (a developer,
 * or the coding assistant, on the machine that runs it): what still needs a look, with the page,
 * the note and where the screenshot is on disk, then marked handled once it is fixed.
 *
 *   php artisan media:feedback                  what still needs a look (reports, and ratings that were not good or have a note)
 *   php artisan media:feedback --all            everything, handled too
 *   php artisan media:feedback --summary        how each action is rated, worst first
 *   php artisan media:feedback --done=3 --done=7 --note="Fixed the board scrolling"
 */
class FeedbackReport extends Command
{
    protected $signature = 'media:feedback
        {--all : Everything, handled items too}
        {--summary : How each action is rated, worst first}
        {--done=* : Mark these ids handled}
        {--open=* : Mark these ids as needing a look again}
        {--note= : With --done: what was changed (kept on the item)}
        {--limit=50 : How many to show}';

    protected $description = 'Read the feedback sent while trying the app out, and mark items handled';

    public function handle(): int
    {
        if ($ids = array_filter(array_map('intval', (array) $this->option('done')))) {
            foreach (BetaFeedback::whereIn('id', $ids)->get() as $f) {
                $note = trim((string) $this->option('note'));
                $f->update(['done_at' => now(), 'fix' => $note !== '' ? $note : $f->fix]);
            }
            $this->info('Marked handled: '.implode(', ', $ids));

            return self::SUCCESS;
        }
        if ($ids = array_filter(array_map('intval', (array) $this->option('open')))) {
            BetaFeedback::whereIn('id', $ids)->update(['done_at' => null]);
            $this->info('Open again: '.implode(', ', $ids));

            return self::SUCCESS;
        }

        if ($this->option('summary')) {
            $rows = BetaFeedback::query()->where('kind', 'rating')->whereNotNull('action')
                ->selectRaw('action, count(*) as n, sum(rating = 1) as bad, sum(rating = 2) as meh, sum(rating = 3) as good')
                ->groupBy('action')->get()
                ->sortByDesc(fn ($a) => ($a->bad * 2 + $a->meh) / max(1, $a->n));
            $this->table(['Action', 'Ratings', 'Not good', 'OK', 'Good'], $rows->map(fn ($a) => [Words::actionLabel($a->action).' ('.$a->action.')', $a->n, $a->bad, $a->meh, $a->good]));

            return self::SUCCESS;
        }

        $items = BetaFeedback::query()->with('user.profile')->latest()
            ->unless($this->option('all'), fn ($q) => $q->whereNull('done_at')
                ->where(fn ($q) => $q->where('kind', 'report')->orWhere('rating', '<', 3)->orWhereNotNull('note')))
            ->limit(max(1, (int) $this->option('limit')))->get();

        if ($items->isEmpty()) {
            $this->info('Nothing needs a look.');

            return self::SUCCESS;
        }

        foreach ($items as $f) {
            $who = $f->user?->profile?->nameForLocale('en') ?? $f->user?->name ?? '?';
            $what = $f->kind === 'report' ? 'REPORT' : 'RATING '.['', 'not good', 'OK', 'good'][$f->rating ?? 0];
            $this->line(str_repeat('-', 72));
            $this->line(sprintf('#%d  %s  %s  by %s (%s)%s', $f->id, $f->created_at->format('Y-m-d H:i'), $what, $who, $f->user?->myRole() ?? '-', $f->done_at ? '  [handled]' : ''));
            if ($f->action) {
                $this->line('  action:  '.Words::actionLabel($f->action).' ('.$f->action.')');
            }
            $this->line('  page:    '.$f->url);
            if ($f->viewport) {
                $this->line('  screen:  '.$f->viewport.'  '.$f->user_agent);
            }
            if ($f->note) {
                $this->line('  note:    '.str_replace("\n", "\n           ", $f->note));
            }
            if ($f->screenshot && Storage::disk('local')->exists($f->screenshot)) {
                $this->line('  shot:    '.Storage::disk('local')->path($f->screenshot));
            }
            if ($f->fix) {
                $this->line('  fixed:   '.$f->fix);
            }
        }
        $this->line(str_repeat('-', 72));
        $this->line($items->count().' shown. Mark handled: php artisan media:feedback --done=ID --note="what changed"');

        return self::SUCCESS;
    }
}
