<?php

namespace App\Livewire\MediaRequests;

use App\Models\City;
use App\Models\MediaAssistantLesson;
use App\Models\MediaAssistantLog;
use App\Models\Program;
use App\Support\MediaRequests\MediaAssistant;
use App\Support\MediaRequests\MediaCatalog;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Teach the assistant": everything people typed to the request assistant.
 * The ones it did not understand, and the ones where the person picked
 * something else, show which words it is missing. The media manager teaches it
 * from any line: the word people used, and what it means (the kind of request,
 * a program, a governorate). MediaAssistant reads these lessons first.
 */
class AssistantLog extends Component
{
    use WithPagination;

    #[Url(as: 'show')]
    public string $filter = 'missed';

    /** The line being taught from, and the lesson being written. */
    public ?int $teaching = null;

    public string $phrase = '';

    /** "service.type", e.g. "001.new" */
    public string $kind = '';

    public string $format = '';

    public ?int $program_id = null;

    public ?int $city_id = null;

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['missed', 'corrected', 'all', 'lessons'], true) ? $filter : 'missed';
        $this->resetPage();
    }

    /** Open the form under a line, filled with what the person finally asked for when there is one. */
    public function teach(int $logId): void
    {
        $this->authorizeTeaching();
        $log = MediaAssistantLog::findOrFail($logId);

        $this->resetErrorBag();
        $this->teaching = $log->id;
        $this->phrase = mb_substr($log->text, 0, 120);
        $service = $log->final_service ?? $log->service;
        $type = $log->final_type ?? $log->type;
        $this->kind = $service && $type ? $service.'.'.$type : '';
        $this->format = (string) ($log->mediaRequest?->answers['format'] ?? '');
        $this->program_id = $log->mediaRequest?->program_id;
        $this->city_id = $log->mediaRequest?->city_id;
    }

    public function cancelTeach(): void
    {
        $this->reset(['teaching', 'phrase', 'kind', 'format', 'program_id', 'city_id']);
        $this->resetErrorBag();
    }

    public function saveLesson(MediaCatalog $catalog): void
    {
        $this->authorizeTeaching();

        $kinds = collect($catalog->requestServices())->flatMap(fn ($s) => array_map(fn ($t) => $s.'.'.$t, $catalog->types($s)))->all();
        $data = $this->validate([
            'phrase' => ['required', 'string', 'min:2', 'max:120'],
            'kind' => ['nullable', Rule::in($kinds)],
            'format' => ['nullable', Rule::in($this->formats($catalog))],
            'program_id' => ['nullable', 'exists:programs,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
        ], [], ['phrase' => __('media.al.t.phrase')]);

        if (! $data['kind'] && ! $data['program_id'] && ! $data['city_id']) {
            $this->addError('kind', __('media.al.t.needMeaning'));

            return;
        }

        [$service, $type] = $data['kind'] ? explode('.', $data['kind']) : [null, null];
        $phrase = trim(preg_replace('/\s+/u', ' ', $data['phrase']) ?? '');
        $lesson = MediaAssistantLesson::updateOrCreate(['phrase' => $phrase], [
            'service' => $service, 'type' => $type,
            'format' => $data['kind'] === '001.new' ? ($data['format'] ?: null) : null,
            'program_id' => $data['program_id'], 'city_id' => $data['city_id'],
            'employee_id' => auth()->user()->employee_id,
        ]);
        if ($this->teaching) {
            MediaAssistantLog::whereKey($this->teaching)->update(['lesson_id' => $lesson->id]);
        }

        $this->cancelTeach();
        $this->dispatch('toast', message: __('media.al.t.saved'));
    }

    public function deleteLesson(int $id): void
    {
        $this->authorizeTeaching();
        MediaAssistantLesson::whereKey($id)->delete();
    }

    public function render(MediaCatalog $catalog)
    {
        $base = MediaAssistantLog::query();
        $programs = Program::orderBy('code')->get();
        $cities = City::orderBy('code')->get();
        $assistant = app(MediaAssistant::class);

        return view('livewire.media-requests.assistant-log', [
            'rows' => $this->filter === 'lessons' ? null : MediaAssistantLog::query()
                ->with(['employee', 'program', 'city', 'mediaRequest', 'lesson'])
                ->when($this->filter === 'missed', fn (Builder $q) => $q->where('understood', false))
                ->when($this->filter === 'corrected', fn (Builder $q) => $q->where('corrected', true))
                ->latest('id')
                ->paginate(40),
            'lessons' => $this->filter === 'lessons' ? MediaAssistantLesson::with(['program', 'city', 'teacher'])->latest('id')->get() : collect(),
            'counts' => [
                'missed' => (clone $base)->where('understood', false)->count(),
                'corrected' => (clone $base)->where('corrected', true)->count(),
                'all' => (clone $base)->count(),
                'lessons' => MediaAssistantLesson::count(),
            ],
            // How the assistant reads a line now, with what it was taught since.
            'now' => fn (string $text) => $assistant->analyze($text, $programs, $cities),
            'canTeach' => app(MediaTeam::class)->canActAsMediaManager(auth()->user()),
            'kinds' => collect($catalog->requestServices())->mapWithKeys(fn ($s) => collect($catalog->types($s))
                ->mapWithKeys(fn ($t) => [$s.'.'.$t => __('media.svc.'.$catalog->serviceKey($s)).' · '.__('media.type.'.$t)]))->all(),
            'formats' => $this->formats($catalog),
            'programs' => $programs,
            'cities' => $cities,
        ]);
    }

    /** @return array<int, string> the kinds of new design (poster, certificate…) */
    private function formats(MediaCatalog $catalog): array
    {
        return collect($catalog->fields('001', 'new'))->firstWhere('key', 'format')['options'] ?? [];
    }

    /** Teaching changes how everyone's requests are read: the media manager only. */
    private function authorizeTeaching(): void
    {
        abort_unless(app(MediaTeam::class)->canActAsMediaManager(auth()->user()), 403);
    }
}
