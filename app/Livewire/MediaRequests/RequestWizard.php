<?php

namespace App\Livewire\MediaRequests;

use App\Models\City;
use App\Models\MediaAssistantLog;
use App\Models\MediaRequest;
use App\Models\Program;
use App\Services\MediaRequestWorkflow;
use App\Support\MediaRequests\HcGenerator;
use App\Support\MediaRequests\HcPack;
use App\Support\MediaRequests\MediaAssistant;
use App\Support\MediaRequests\MediaCatalog;
use App\Support\MediaRequests\MediaNaming;
use App\Support\MediaRequests\MediaTeam;
use App\Support\MediaRequests\TeamLoad;
use App\Support\MediaRequests\WorkCalendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The request form: a pop-up with four step cards.
 *
 *   1 what      service and type, or describe it to the assistant
 *   2 details   the questions for that type; follow-ups appear as you answer
 *   3 who/when  program, governorate, short name, needed-by date
 *   4 extras    optional questions, brief, delivery, files
 *
 * Nothing is saved until the last step; MediaRequestWorkflow validates it all
 * again, so skipping steps in the browser gets nowhere.
 */
class RequestWizard extends Component
{
    use WithFileUploads;

    public bool $show = false;

    public int $step = 1;

    public ?int $editingId = null;

    public ?string $service = null;

    public ?string $type = null;

    public array $answers = [];

    public ?int $program_id = null;

    public ?int $city_id = null;

    public string $title = '';

    public string $description = '';

    public ?string $needed_by = null;

    public string $rush_reason = '';

    public ?string $delivery_channel = null;

    public string $delivery_contact = '';

    public array $uploads = [];

    public string $prompt = '';

    /** @var array<int, array<string, mixed>> */
    public array $questions = [];

    public array $questionAnswers = [];

    public bool $assistantUsed = false;

    public bool $assistantMissed = false;

    /** Row in media_assistant_logs for the last analysis; the browser cannot change it. */
    #[Locked]
    public ?int $assistantLogId = null;

    /** The day picked on the calendar, if the form was opened from one (Y-m-d). */
    #[Locked]
    public ?string $presetDate = null;

    /** The person chose to change the event date that came from the calendar. */
    public bool $changeEventDate = false;

    #[On('media-wizard-open')]
    public function open(?int $id = null, ?string $prompt = null, ?string $date = null): void
    {
        $this->resetForm();

        // Opened from a calendar day: that day is the needed-by date, or the event date for a shoot (applyPresetDate).
        if ($id === null && $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= today()->toDateString()) {
            $this->presetDate = $date;
            $this->needed_by = $date;
        }

        // Opened from the "describe what you need" bar: read it straight away.
        if ($id === null && $prompt !== null && trim($prompt) !== '') {
            $this->prompt = mb_substr(trim($prompt), 0, 1000);
            $this->analyze(app(MediaAssistant::class));
        }

        if ($id !== null) {
            $request = MediaRequest::findOrFail($id);
            Gate::authorize('update', $request);

            $this->fill([
                'editingId' => $request->id,
                'service' => $request->service,
                'type' => $request->type,
                'answers' => $request->answers ?? [],
                'program_id' => $request->program_id,
                'city_id' => $request->city_id,
                'title' => (string) $request->title,
                'description' => (string) $request->description,
                'needed_by' => $request->needed_by?->toDateString(),
                'rush_reason' => (string) $request->rush_reason,
                'delivery_channel' => $request->delivery_channel,
                'delivery_contact' => (string) $request->delivery_contact,
                'step' => 2,
            ]);
        }

        $this->show = true;
    }

    /** The proofreader sends the checked description back. */
    #[On('media-proofread-apply')]
    public function applyProof(string $target, string $text): void
    {
        if ($target === 'description' && $this->show) {
            $this->description = mb_substr($text, 0, 5000);
        }
    }

    public function close(): void
    {
        $this->show = false;
        $this->resetForm();
    }

    public function chooseType(string $service, string $type): void
    {
        if ($this->editingId !== null || ! app(MediaCatalog::class)->isValidType($service, $type)) {
            return;
        }

        if ($service !== $this->service || $type !== $this->type) {
            $this->answers = app(MediaCatalog::class)->defaults($service, $type);
        }

        $this->service = $service;
        $this->type = $type;
        $this->resetErrorBag('service');
        $this->applyPresetDate();
    }

    /** Read the description, pick the type, pre-fill, and ask only what is still missing. */
    public function analyze(MediaAssistant $assistant): void
    {
        if ($this->editingId !== null || trim($this->prompt) === '') {
            return;
        }

        $result = $assistant->analyze(mb_substr($this->prompt, 0, 1000), $this->programs(), $this->cities());

        $this->assistantMissed = $result['service'] === null;
        $this->assistantUsed = ! $this->assistantMissed;
        $this->recordAssistant($result);

        if ($this->assistantMissed) {
            $this->questions = [];

            return;
        }

        $catalog = app(MediaCatalog::class);
        $this->service = $result['service'];
        $this->type = $result['type'];
        $this->answers = array_replace($catalog->defaults($this->service, $this->type), $result['answers']);
        $this->program_id = $result['program_id'] ?? $this->program_id;
        $this->city_id = $result['city_id'] ?? $this->city_id;
        $this->needed_by = $result['needed_by'] ?? $this->needed_by;
        $this->questions = $result['questions'];
        $this->questionAnswers = [];
        $this->applyPresetDate();
    }

    public function next(): void
    {
        $this->validateStep($this->step);

        if ($this->step === 1) {
            $this->applyQuestionAnswers();
        }

        $this->step = min(4, $this->step + 1);

        // No date yet: suggest the earliest the team can do it in working time.
        if ($this->step === 3 && ! $this->needed_by && $this->service) {
            $this->needed_by = $this->suggestedDate()?->toDateString();
        }
    }

    public function back(): void
    {
        $this->step = max($this->editingId ? 2 : 1, $this->step - 1);
    }

    /** Jump back to a finished step; never forward past one that is not valid yet. */
    public function goTo(int $step): void
    {
        $min = $this->editingId ? 2 : 1;

        if ($step >= $min && $step < $this->step) {
            $this->step = $step;
        }
    }

    public function submit(MediaRequestWorkflow $workflow)
    {
        foreach ([1, 2, 3, 4] as $step) {
            if ($step === 1 && $this->editingId) {
                continue;
            }

            try {
                $this->validateStep($step);
            } catch (ValidationException $e) {
                $this->step = $step;

                throw $e;
            }
        }

        $data = [
            'service' => $this->service,
            'type' => $this->type,
            'answers' => $this->answers,
            'program_id' => $this->program_id,
            'city_id' => $this->city_id,
            'title' => $this->title,
            'description' => $this->description,
            'needed_by' => $this->needed_by,
            'rush_reason' => $this->rush_reason,
            'delivery_channel' => $this->delivery_channel ?: null,
            'delivery_contact' => $this->delivery_contact,
        ];

        $user = auth()->user();

        if ($this->editingId) {
            $request = $workflow->update($user, MediaRequest::findOrFail($this->editingId), $data);
            $workflow->attach($user, $request, $this->uploads);
        } else {
            $request = $workflow->submit($user, $data, $this->uploads);
            $this->linkAssistant($request);
        }

        $this->show = false;
        $this->resetForm();
        $this->dispatch('media-request-saved');

        // People who only ask for work see it on their home screen.
        if (app(\App\Support\MediaRequests\MediaTeam::class)->isRequesterOnly($user)) {
            return $this->redirectRoute('media-requests.home', ['open' => $request->id], navigate: false);
        }

        return $this->redirectRoute('media-requests.show', $request, navigate: false);
    }

    public function render()
    {
        [$catalog, $naming, $pack] = [app(MediaCatalog::class), app(MediaNaming::class), app(HcPack::class)];
        $programs = $this->programs();
        $cities = $this->cities();
        $program = $programs->firstWhere('id', $this->program_id);
        $city = $cities->firstWhere('id', $this->city_id);
        $itemCode = $this->service ? $naming->itemCode($this->service, (string) $this->type, $this->answers) : 'Design';
        $requester = auth()->user()->profile;

        return view('livewire.media-requests.request-wizard', [
            'catalog' => $catalog,
            'programs' => $programs,
            'cities' => $cities,
            'details' => $this->service ? $catalog->visibleFields($this->service, $this->type, $this->answers, 'details') : [],
            'extras' => $this->service ? $catalog->visibleFields($this->service, $this->type, $this->answers, 'extra') : [],
            'prefix' => $naming->prefix($program, $city, $itemCode),
            'suggestions' => $this->step === 1 && trim($this->prompt) !== '' ? $pack->suggest($this->prompt, $programs, 4) : collect(),
            'generator' => $this->step === 1 && $this->editingId === null
                ? app(HcGenerator::class)->suggest($this->prompt, $this->service, $this->type, $this->answers) : null,
            'rush' => app(MediaRequestWorkflow::class)->isRush($this->needed_by, $this->service, $this->type, $this->answers),
            'earliest' => $this->service ? app(WorkCalendar::class)->earliest($this->service, (string) $this->type, $this->answers) : null,
            'suggested' => $this->suggestedDate(),
            'load' => $this->service ? app(TeamLoad::class)->of(app(MediaTeam::class)->specialty($this->service, $this->type)) : null,
            'outside' => $this->service && app(WorkCalendar::class)->needsOutside($this->service, (string) $this->type, $this->answers),
            'twoApprovals' => app(MediaRequestWorkflow::class)->needsProgramApproval($requester, $program),
        ]);
    }

    // ------------------------------------------------------------------

    private function validateStep(int $step): void
    {
        $catalog = app(MediaCatalog::class);

        match ($step) {
            1 => $this->service && $catalog->isValidType($this->service, $this->type)
                ?: throw ValidationException::withMessages(['service' => __('media.err.pick')]),
            2 => $this->throwMissing($catalog->missing($this->service, $this->type, $this->answers, 'details')),
            3 => $this->validate([
                'program_id' => ['required', 'integer', 'exists:programs,id'],
                'city_id' => ['required', 'integer', 'exists:cities,id'],
                'title' => ['required', 'string', 'max:80', 'regex:'.MediaRequestWorkflow::TITLE_PATTERN],
                'needed_by' => ['required', 'date', 'after_or_equal:today', MediaRequestWorkflow::workDayRule()],
                'rush_reason' => [app(MediaRequestWorkflow::class)->isRush($this->needed_by, $this->service, $this->type, $this->answers) ? 'required' : 'nullable', 'string', 'max:1000'],
            ], [
                'title.regex' => __('media.err.nameEnglish'),
                'rush_reason.required' => __('media.err.reason'),
                'needed_by.after_or_equal' => __('media.err.date'),
            ], [
                'program_id' => __('media.f.program'),
                'city_id' => __('media.f.city'),
                'title' => __('media.f.name'),
                'needed_by' => __('media.f.deadline'),
            ]),
            4 => $this->validateExtras($catalog),
            default => null,
        };
    }

    private function validateExtras(MediaCatalog $catalog): void
    {
        $this->throwMissing($catalog->missing($this->service, $this->type, $this->answers, 'extra'));

        $this->validate([
            'description' => ['nullable', 'string', 'max:5000'],
            'delivery_channel' => ['nullable', 'in:'.implode(',', config('media-requests.delivery_channels'))],
            'delivery_contact' => ['nullable', 'string', 'max:190'],
            'uploads' => ['array', 'max:10'],
            'uploads.*' => ['file', 'max:51200', 'mimes:jpg,jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,ai,psd,mp4,mov'],
        ]);
    }

    private function throwMissing(array $missing): void
    {
        if ($missing !== []) {
            throw ValidationException::withMessages(
                collect($missing)->mapWithKeys(fn (string $m, string $k) => ['answers.'.$k => $m])->all(),
            );
        }
    }

    /** Answers to the assistant's questions go where they belong; the rest becomes part of the brief. */
    private function applyQuestionAnswers(): void
    {
        $notes = [];

        foreach ($this->questions as $q) {
            $value = trim((string) ($this->questionAnswers[$q['id']] ?? ''));

            if ($value === '') {
                continue;
            }

            $target = $q['target'] ?? null;

            // The request name must be English; an Arabic answer goes into the brief instead.
            if ($target === 'title' && $this->title === '' && preg_match(MediaRequestWorkflow::TITLE_PATTERN, $value)) {
                $this->title = mb_substr($value, 0, 80);
            } elseif (is_string($target) && str_starts_with($target, 'answers.')) {
                $this->answers[substr($target, 8)] = $value;
            } elseif (in_array($target, ['program_id', 'city_id'], true)) {
                $this->{$target} = (int) $value ?: null;
            } elseif ($target === 'needed_by') {
                $this->needed_by = $value;
            } else {
                $notes[] = $q[app()->getLocale() === 'ar' ? 'ar' : 'en'].' '.$value;
            }
        }

        if ($notes !== []) {
            $this->description = trim($this->description."\n".implode("\n", $notes));
        }

        $this->questions = [];
        $this->questionAnswers = [];
    }

    private function resetForm(): void
    {
        $this->reset([
            'step', 'editingId', 'presetDate', 'changeEventDate', 'service', 'type', 'answers', 'program_id', 'city_id', 'title',
            'description', 'needed_by', 'rush_reason', 'delivery_channel', 'delivery_contact',
            'uploads', 'prompt', 'questions', 'questionAnswers', 'assistantUsed', 'assistantMissed', 'assistantLogId',
        ]);
        $this->resetErrorBag();
    }

    /** Programs are country-wide and shown by code + slug, never translated. */
    /** The earliest date in working time, plus the days it waits because the team of its field is busy. */
    private function suggestedDate(): ?\Illuminate\Support\Carbon
    {
        if (! $this->service) {
            return null;
        }

        $wait = app(TeamLoad::class)->of(app(MediaTeam::class)->specialty($this->service, $this->type))['extraDays'];

        return app(WorkCalendar::class)->earliest($this->service, (string) $this->type, $this->answers, null, $wait);
    }

    /**
     * A day picked on the calendar: for a shoot it is the event date (not asked again), and the
     * needed-by date becomes the turnaround after the event; for anything else it is the needed-by date.
     */
    private function applyPresetDate(): void
    {
        if (! $this->presetDate || ! $this->service) {
            return;
        }

        $hasEventDate = collect(app(MediaCatalog::class)->fields($this->service, (string) $this->type))->contains('key', 'eventDate');
        if (! $hasEventDate) {
            $this->needed_by = $this->presetDate;

            return;
        }

        $this->answers['eventDate'] = $this->presetDate;
        $days = app(WorkCalendar::class)->plan($this->service, (string) $this->type, $this->answers)[0] ?? 2;
        $this->needed_by = \Illuminate\Support\Carbon::parse($this->presetDate)->addWeekdays($days)->toDateString();
    }

    private function programs(): Collection
    {
        return Program::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'slug', 'name']);
    }

    private function cities(): Collection
    {
        return City::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'slug', 'name_en', 'name_ar']);
    }

    /** Keep what was typed and what the assistant made of it, to teach it new words later. */
    private function recordAssistant(array $result): void
    {
        $this->assistantLogId = MediaAssistantLog::create([
            'employee_id' => auth()->user()->employee_id,
            'text' => mb_substr(trim($this->prompt), 0, 1000),
            'understood' => $result['service'] !== null,
            'service' => $result['service'],
            'type' => $result['type'],
            'answers' => $result['answers'] ?: null,
            'program_id' => $result['program_id'],
            'city_id' => $result['city_id'],
            'needed_by' => $result['needed_by'],
            'asked' => array_column($result['questions'], 'id') ?: null,
        ])->id;
    }

    /** After sending: what was finally asked for, and whether the guess was changed. */
    private function linkAssistant(MediaRequest $request): void
    {
        if ($this->assistantLogId === null) {
            return;
        }

        $log = MediaAssistantLog::query()
            ->where('employee_id', auth()->user()->employee_id)
            ->whereNull('media_request_id')
            ->find($this->assistantLogId);

        $log?->update([
            'media_request_id' => $request->id,
            'final_service' => $request->service,
            'final_type' => $request->type,
            'corrected' => $log->understood && ($log->service !== $request->service || $log->type !== $request->type),
        ]);
    }
}
