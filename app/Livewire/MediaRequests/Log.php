<?php

namespace App\Livewire\MediaRequests;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Models\MediaRequest;
use App\Notifications\MediaRequestUpdated;
use App\Support\MediaRequests\MediaCatalog;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything everyone did on media requests, newest first: who asked, who
 * approved, who edited what, who delivered. Exportable to CSV for Excel.
 */
class Log extends Component
{
    use AuthorizesActivePermissions;
    use WithPagination;

    public const EVENTS = [
        'submitted', 'packAsked', 'edited', 'replied', 'cancelled', 'pmApproved', 'mmApproved', 'info',
        'rejected', 'hold', 'resumed', 'assigned', 'claimed', 'started', 'draft', 'sentInt', 'sentReq',
        'intOk', 'intBack', 'reqOk', 'changes', 'ready', 'delivered', 'packDone', 'comment', 'nudged', 'asset',
    ];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'event')]
    public string $event = '';

    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    public function resetFilters(): void
    {
        $this->reset(['search', 'event', 'from', 'to']);
        $this->resetPage();
    }

    public function exportCsv(MediaCatalog $catalog): StreamedResponse
    {
        $this->authorizeActivePermission('media-requests.export');

        $locale = app()->getLocale();
        $query = $this->query();

        return response()->streamDownload(function () use ($query, $catalog, $locale): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads Arabic as UTF-8
            fputcsv($out, [__('media.lg.col.when'), __('media.lg.col.who'), __('media.lg.col.what'), __('media.lg.col.req'), __('media.lg.col.details')]);

            $query->chunk(500, function ($rows) use ($out, $catalog, $locale): void {
                foreach ($rows as $a) {
                    fputcsv($out, [
                        $a->created_at->format('Y-m-d H:i'),
                        $a->causer?->profile?->nameForLocale($locale) ?? '',
                        __('media.lg.'.$a->description),
                        trim(($a->subject?->reference ?? '').' '.($a->subject?->displayName() ?? '')),
                        self::details($a, $catalog),
                    ]);
                }
            });

            fclose($out);
        }, 'media-requests-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        return view('livewire.media-requests.log', [
            'rows' => $this->query()->paginate(50),
            'catalog' => app(MediaCatalog::class),
            'events' => self::EVENTS,
        ]);
    }

    /** One readable line per entry: the note, the link, or which fields were changed. */
    public static function details(Activity $a, MediaCatalog $catalog): string
    {
        $p = $a->properties;
        $parts = [];

        if ($a->description === 'submitted' && ($subject = $a->subject) instanceof MediaRequest) {
            $parts[] = collect($catalog->summary($subject->service, $subject->type, $subject->answers ?? []))
                ->map(fn (array $row) => $row['label'].': '.$row['value'])
                ->implode(' · ');
        }

        if ($changes = $p->get('changes')) {
            $parts[] = __('media.lg.changed', ['x' => collect(array_keys($changes))
                ->map(fn (string $k) => str_starts_with($k, 'answers.') ? $catalog->fieldLabel(substr($k, 8)) : __('media.lf.'.$k))
                ->implode('، ')]);
        }

        if ($p->get('prio')) {
            $parts[] = __('media.prio').': '.__('media.prio.'.$p->get('prio'));
        }

        if ($p->get('assignee')) {
            $parts[] = MediaRequestUpdated::localizeParams(['to' => $p->get('assignee')], app()->getLocale())['to'];
        }

        foreach (['note', 'link', 'archive_name', 'files'] as $key) {
            if ($value = $p->get($key)) {
                $parts[] = (string) $value;
            }
        }

        return implode(' · ', array_filter($parts));
    }

    private function query(): Builder
    {
        return Activity::query()
            ->where('log_name', MediaRequest::LOG_NAME)
            ->with(['causer.profile', 'subject'])
            ->when($this->event !== '', fn (Builder $q) => $q->where('description', $this->event))
            ->when($this->from, fn (Builder $q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn (Builder $q) => $q->whereDate('created_at', '<=', $this->to))
            ->when(trim($this->search) !== '', function (Builder $q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(fn (Builder $w) => $w
                    ->where('properties', 'like', $term)
                    ->orWhereHasMorph('subject', [MediaRequest::class], fn (Builder $s) => $s->where('reference', 'like', $term)->orWhere('title', 'like', $term))
                    ->orWhereHasMorph('causer', [\App\Models\User::class], fn (Builder $c) => $c->whereHas('profile', fn (Builder $e) => $e
                        ->where('first_name_en', 'like', $term)->orWhere('first_name_ar', 'like', $term)
                        ->orWhere('last_name_en', 'like', $term)->orWhere('last_name_ar', 'like', $term))));
            })
            ->latest('id');
    }
}
