<?php

namespace App\Services;

use App\Enums\MediaRequestStatus as S;
use App\Models\Employee;
use App\Models\MediaRequest;
use App\Models\MediaRequestComment;
use App\Models\Program;
use App\Models\User;
use App\Support\MediaRequests\HcPack;
use App\Support\MediaRequests\MediaCatalog;
use App\Support\MediaRequests\MediaFolders;
use App\Support\MediaRequests\MediaNaming;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a media request goes through here, in the same four steps:
 *
 *   1. MediaRequestPolicy decides whether this person may take this step now;
 *   2. the request moves (status, status_changed_at, return_to);
 *   3. the step is written to the activity log (log name "media-requests"),
 *      which is the history on the request page and the team's LOG;
 *   4. MediaRequestNotifier tells the next owner it is their turn.
 *
 * The Livewire screens only collect input and call one method here, so the
 * rules cannot drift between screens.
 */
class MediaRequestWorkflow
{
    /**
     * The short request name goes into the archive file name
     * (005DISC_ALP_Certificate_<name>_V1_…), so it is English letters, digits,
     * spaces, "-" and "_" only.
     */
    public const TITLE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 _-]*$/';

    /** Request fields the requester can change; the log lists the ones that changed. */
    private const EDITABLE = [
        'program_id', 'city_id', 'title', 'description', 'needed_by', 'rush_reason',
        'delivery_channel', 'delivery_contact',
    ];

    public function __construct(
        private MediaTeam $team,
        private MediaCatalog $catalog,
        private MediaNaming $naming,
        private MediaRequestNotifier $notifier,
        private ActivityLogger $logger,
        private HcPack $pack,
    ) {}

    // ------------------------------------------------------------------
    // Filing
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  service, type, answers, program_id, city_id, title,
     *                                      description, needed_by, rush_reason, delivery_channel, delivery_contact
     * @param  array<int, UploadedFile>  $files
     */
    public function submit(User $actor, array $data, array $files = []): MediaRequest
    {
        Gate::forUser($actor)->authorize('create', MediaRequest::class);

        $data = $this->validated($data);
        $requester = $actor->profile;
        $program = Program::find($data['program_id']);
        $needsProgram = $this->needsProgramApproval($requester, $program);

        $request = $this->createWithReference($data['service'], fn (string $reference) => MediaRequest::create([
            ...$data,
            'reference' => $reference,
            'requester_id' => $requester->id,
            'item_code' => $this->naming->itemCode($data['service'], $data['type'], $data['answers']),
            'status' => $needsProgram ? S::ProgramApproval : S::MediaApproval,
            'status_changed_at' => now(),
            'needs_program_approval' => $needsProgram,
        ]));

        $this->addFiles($request, $files);

        $this->log($request, $actor, 'submitted', null, [
            'service' => $request->service,
            'type' => $request->type,
            'title' => $request->title,
            'needed_by' => $request->needed_by?->toDateString(),
            'files' => count($files) ?: null,
        ]);
        $this->notifier->event($request, 'submitted', $actor);

        return $request;
    }

    /**
     * A ready file from HC_PACK: no details and no approvals. The request only
     * records who asked and when; any team member sends the files.
     *
     * @param  array<int, string>  $paths
     */
    public function requestPack(User $actor, array $paths, ?string $note = null): MediaRequest
    {
        Gate::forUser($actor)->authorize('create', MediaRequest::class);

        $files = $this->pack->find(array_values(array_filter($paths, 'is_string')));

        if ($files->isEmpty()) {
            throw ValidationException::withMessages(['paths' => __('media.err.pick')]);
        }

        $note = $this->cleanNote($note);

        $request = $this->createWithReference('000', fn (string $reference) => MediaRequest::create([
            'reference' => $reference,
            'requester_id' => $actor->employee_id,
            'service' => '000',
            'type' => 'pack',
            'item_code' => 'HCPACK',
            'title' => mb_substr($files->count() === 1 ? $files->first()['name'] : $files->first()['name'].' +'.($files->count() - 1), 0, 120),
            'description' => $note,
            'pack_files' => $files->pluck('path')->all(),
            'status' => S::Delivery,
            'status_changed_at' => now(),
            'needs_program_approval' => false,
        ]));

        $this->log($request, $actor, 'packAsked', null, ['n' => $files->count(), 'note' => $note]);
        $this->notifier->event($request, 'packAsked', $actor, ['n' => $files->count()]);

        return $request;
    }

    /** Direct edit while the request waits for approval or for missing information. */
    public function update(User $actor, MediaRequest $r, array $data): MediaRequest
    {
        Gate::forUser($actor)->authorize('update', $r);

        $data = $this->validated(['service' => $r->service, 'type' => $r->type] + $data);
        $from = $r->status;
        $before = $r->only([...self::EDITABLE, 'answers']);

        $r->fill(collect($data)->only([...self::EDITABLE, 'answers'])->all());
        $r->item_code = $this->naming->itemCode($r->service, $r->type, $r->answers ?? []);

        $changes = $this->changedFields($before, $r);

        if ($changes === []) {
            return $r;
        }

        // A different program means a different program manager; an approval
        // given for the old program does not carry over.
        if (array_key_exists('program_id', $changes) && in_array($r->status, [S::ProgramApproval, S::MediaApproval], true)) {
            $needsProgram = $this->needsProgramApproval($r->requester, Program::find($r->program_id));
            $r->fill([
                'needs_program_approval' => $needsProgram,
                'program_approver_id' => null,
                'program_approved_at' => null,
            ]);
            $this->move($r, $needsProgram ? S::ProgramApproval : S::MediaApproval);
        }

        return $this->commit($r, $actor, 'edited', $from, ['changes' => $changes]);
    }

    // ------------------------------------------------------------------
    // Approvals
    // ------------------------------------------------------------------

    public function approveProgram(User $actor, MediaRequest $r, ?string $note = null): MediaRequest
    {
        Gate::forUser($actor)->authorize('approveProgram', $r);

        $from = $r->status;
        $r->fill(['program_approver_id' => $actor->employee_id, 'program_approved_at' => now()]);
        $this->move($r, S::MediaApproval);

        return $this->commit($r, $actor, 'pmApproved', $from, ['note' => $this->cleanNote($note)]);
    }

    /** Release the request to the team, optionally straight to one member. */
    public function approveMedia(User $actor, MediaRequest $r, string $priority, ?string $plannedFor = null, ?int $assigneeId = null): MediaRequest
    {
        Gate::forUser($actor)->authorize('approveMedia', $r);

        Validator::make(compact('priority', 'plannedFor'), [
            'priority' => ['required', Rule::in(config('media-requests.priorities'))],
            'plannedFor' => ['nullable', 'date', 'after_or_equal:today'],
        ])->validate();

        $from = $r->status;
        $r->fill([
            'media_approver_id' => $actor->employee_id,
            'media_approved_at' => now(),
            'priority' => $priority,
            'planned_for' => $plannedFor ?: null,
        ]);

        if ($assigneeId === null) {
            $this->move($r, S::ToClaim);

            return $this->commit($r, $actor, 'mmApproved', $from, ['prio' => $priority, 'planned' => $plannedFor], ['prio' => $priority]);
        }

        $assignee = $this->teamMember($assigneeId);
        $r->assignee_id = $assignee->id;
        $this->move($r, S::Assigned);

        $this->log($r, $actor, 'mmApproved', $from, ['prio' => $priority, 'planned' => $plannedFor], save: true);

        return $this->commit($r, $actor, 'assigned', $from, ['assignee' => $assignee->id], ['to' => $assignee->id]);
    }

    // ------------------------------------------------------------------
    // Taking and doing the work
    // ------------------------------------------------------------------

    public function assign(User $actor, MediaRequest $r, int $assigneeId): MediaRequest
    {
        Gate::forUser($actor)->authorize('assign', $r);

        $assignee = $this->teamMember($assigneeId);
        $from = $r->status;
        $r->assignee_id = $assignee->id;
        $this->move($r, S::Assigned);

        return $this->commit($r, $actor, 'assigned', $from, ['assignee' => $assignee->id], ['to' => $assignee->id]);
    }

    /** "I'll take it." */
    public function claim(User $actor, MediaRequest $r): MediaRequest
    {
        Gate::forUser($actor)->authorize('claim', $r);

        $from = $r->status;
        $r->assignee_id = $actor->employee_id;
        $this->move($r, S::Assigned);

        return $this->commit($r, $actor, 'claimed', $from, ['assignee' => $actor->employee_id], ['to' => $actor->employee_id]);
    }

    public function start(User $actor, MediaRequest $r): MediaRequest
    {
        Gate::forUser($actor)->authorize('start', $r);

        $from = $r->status;
        $this->move($r, S::InProgress);

        return $this->commit($r, $actor, 'started', $from);
    }

    /** Show the direction early. The request stays with the designer. */
    public function shareDraft(User $actor, MediaRequest $r, ?string $link, ?string $note = null, array $files = []): MediaRequest
    {
        Gate::forUser($actor)->authorize('work', $r);

        [$link, $names] = $this->linkOrFiles($r, $link, $files, 'draft');
        $r->draft_link = $link ?? $r->draft_link;

        return $this->commit($r, $actor, 'draft', $r->status, ['link' => $link, 'files' => implode(', ', $names) ?: null, 'note' => $this->cleanNote($note)]);
    }

    /**
     * The first version goes to the media manager for the internal check;
     * once that has passed, later versions go straight to the requester.
     */
    public function sendResult(User $actor, MediaRequest $r, ?string $link, ?string $note = null, array $files = []): MediaRequest
    {
        Gate::forUser($actor)->authorize('work', $r);

        $from = $r->status;
        [$link, $names] = $this->linkOrFiles($r, $link, $files, 'result');
        $r->result_link = $link ?? ($names !== [] ? null : $r->result_link);
        $this->move($r, $r->internal_ok ? S::RequesterReview : S::InternalReview);

        return $this->commit($r, $actor, $r->internal_ok ? 'sentReq' : 'sentInt', $from, ['link' => $link, 'files' => implode(', ', $names) ?: null, 'note' => $this->cleanNote($note)]);
    }

    // ------------------------------------------------------------------
    // Reviews
    // ------------------------------------------------------------------

    public function approveInternal(User $actor, MediaRequest $r): MediaRequest
    {
        Gate::forUser($actor)->authorize('reviewInternal', $r);

        $from = $r->status;
        $r->internal_ok = true;

        // Photography and filming: the link the team sent is the delivery; no requester review.
        if ($r->shortPath()) {
            $r->archive_link = $r->result_link;
            $r->archive_name = $this->naming->archiveName($r);
            $r->delivered_at = now();
            $this->move($r, S::Delivered);

            return $this->commit($r, $actor, 'delivered', $from, ['link' => $r->archive_link, 'archive_name' => $r->archive_name]);
        }

        $this->move($r, S::RequesterReview);

        return $this->commit($r, $actor, 'intOk', $from);
    }

    public function returnToDesigner(User $actor, MediaRequest $r, string $note): MediaRequest
    {
        Gate::forUser($actor)->authorize('reviewInternal', $r);

        $from = $r->status;
        $this->move($r, S::InProgress);

        return $this->commit($r, $actor, 'intBack', $from, ['note' => $this->requiredNote($note)]);
    }

    public function approveResult(User $actor, MediaRequest $r): MediaRequest
    {
        Gate::forUser($actor)->authorize('reviewResult', $r);

        $from = $r->status;
        $this->move($r, $r->needsProduction() ? S::Production : S::Delivery);

        return $this->commit($r, $actor, 'reqOk', $from);
    }

    public function requestChanges(User $actor, MediaRequest $r, string $note): MediaRequest
    {
        Gate::forUser($actor)->authorize('reviewResult', $r);

        $note = $this->requiredNote($note);
        $from = $r->status;
        $r->revisions++;
        $this->move($r, S::InProgress);

        return $this->commit($r, $actor, 'changes', $from, ['n' => $r->revisions, 'note' => $note], ['n' => $r->revisions]);
    }

    // ------------------------------------------------------------------
    // Finishing
    // ------------------------------------------------------------------

    /** Printing or outside production is finished. */
    public function markReady(User $actor, MediaRequest $r): MediaRequest
    {
        Gate::forUser($actor)->authorize('markReady', $r);

        $from = $r->status;
        $this->move($r, S::Delivery);

        return $this->commit($r, $actor, 'ready', $from);
    }

    /** Every request ends delivered with an archive link; an HC_PACK file needs none. */
    public function deliver(User $actor, MediaRequest $r, ?string $archiveLink = null): MediaRequest
    {
        Gate::forUser($actor)->authorize('deliver', $r);

        $from = $r->status;

        if ($r->isPack()) {
            if ($r->assignee_id === null) {
                $r->assignee_id = $actor->employee_id;
            }
            $r->delivered_at = now();
            $this->move($r, S::Delivered);

            return $this->commit($r, $actor, 'packDone', $from);
        }

        $r->archive_link = $this->validLink((string) $archiveLink, 'archive_link');
        $r->archive_name = $this->naming->archiveName($r);
        $r->delivered_at = now();
        $this->move($r, S::Delivered);

        return $this->commit($r, $actor, 'delivered', $from, ['link' => $r->archive_link, 'archive_name' => $r->archive_name]);
    }

    // ------------------------------------------------------------------
    // Side paths
    // ------------------------------------------------------------------

    public function askInfo(User $actor, MediaRequest $r, string $note): MediaRequest
    {
        Gate::forUser($actor)->authorize('askInfo', $r);

        $note = $this->requiredNote($note);
        $from = $r->status;
        $r->return_to = $r->status;
        $this->move($r, S::NeedsInfo);

        return $this->commit($r, $actor, 'info', $from, ['note' => $note]);
    }

    public function reply(User $actor, MediaRequest $r, string $note): MediaRequest
    {
        Gate::forUser($actor)->authorize('reply', $r);

        $note = $this->requiredNote($note);
        $from = $r->status;
        $back = $r->return_to ?: S::MediaApproval;
        $r->return_to = null;
        $this->move($r, $back);

        return $this->commit($r, $actor, 'replied', $from, ['note' => $note]);
    }

    public function reject(User $actor, MediaRequest $r, string $note): MediaRequest
    {
        Gate::forUser($actor)->authorize('reject', $r);

        $note = $this->requiredNote($note);
        $from = $r->status;
        $this->move($r, S::Rejected);

        return $this->commit($r, $actor, 'rejected', $from, ['note' => $note]);
    }

    /**
     * Postpone, with a reason. The media manager can at any stage; the program manager at their own
     * approval. Whoever postponed it owns it while it waits, and resumes it.
     */
    public function hold(User $actor, MediaRequest $r, string $note): MediaRequest
    {
        Gate::forUser($actor)->authorize('hold', $r);

        $note = $this->requiredNote($note);
        $from = $r->status;
        $r->fill(['return_to' => $r->status, 'held_by' => $r->status === S::ProgramApproval ? 'program_manager' : 'media_manager']);
        $this->move($r, S::OnHold);

        return $this->commit($r, $actor, 'hold', $from, ['note' => $note]);
    }

    public function resume(User $actor, MediaRequest $r): MediaRequest
    {
        Gate::forUser($actor)->authorize('resume', $r);

        $from = $r->status;
        $back = $r->return_to ?: S::MediaApproval;
        $r->fill(['return_to' => null, 'held_by' => null]);
        $this->move($r, $back);

        return $this->commit($r, $actor, 'resumed', $from);
    }

    public function cancel(User $actor, MediaRequest $r, ?string $note = null): MediaRequest
    {
        Gate::forUser($actor)->authorize('cancel', $r);

        $from = $r->status;
        $this->move($r, S::Cancelled);

        return $this->commit($r, $actor, 'cancelled', $from, ['note' => $this->cleanNote($note)]);
    }

    /** One reminder a day to whoever holds the request. Not a status change. */
    public function nudge(User $actor, MediaRequest $r): MediaRequest
    {
        Gate::forUser($actor)->authorize('nudge', $r);

        $r->last_nudged_at = now();
        $owner = $r->owner();
        $person = match ($owner) {
            'requester' => $r->requester_id,
            'assignee' => $r->assignee_id,
            default => null,
        };

        return $this->commit($r, $actor, 'nudged', $r->status, ['owner' => $owner, 'target' => $person], $person ? ['to' => $person] : ['role' => $owner]);
    }

    // ------------------------------------------------------------------
    // Conversation and files
    // ------------------------------------------------------------------

    public function comment(User $actor, MediaRequest $r, string $body): MediaRequestComment
    {
        Gate::forUser($actor)->authorize('comment', $r);

        $body = trim($body);
        Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:2000']])->validate();

        $comment = $r->comments()->create(['employee_id' => $actor->employee_id, 'body' => $body]);

        $this->log($r, $actor, 'comment', null, ['note' => mb_substr($body, 0, 300)]);
        $this->notifier->event($r, 'comment', $actor);

        return $comment;
    }

    /** @param  array<int, UploadedFile>  $files */
    public function attach(User $actor, MediaRequest $r, array $files): MediaRequest
    {
        Gate::forUser($actor)->authorize('attach', $r);

        if ($files === []) {
            return $r;
        }

        $names = $this->addFiles($r, $files);
        $this->log($r, $actor, 'asset', null, ['files' => implode(', ', $names)]);

        return $r;
    }

    // ------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------

    /**
     * Requests from the program's own manager skip the program step. When the
     * program has no manager on record, nobody could approve it, so it skips too.
     */
    public function needsProgramApproval(?Employee $requester, ?Program $program): bool
    {
        if ($program === null) {
            return false;
        }

        return ! $this->team->managesProgram($requester, $program->id)
            && $this->team->programManagers($program)->isNotEmpty();
    }

    /** @return array<string, mixed> */
    private function validated(array $data): array
    {
        $service = (string) ($data['service'] ?? '');
        $type = (string) ($data['type'] ?? '');
        $channels = config('media-requests.delivery_channels', []);

        $valid = Validator::make($data, [
            'service' => ['required', Rule::in($this->catalog->requestServices())],
            'type' => ['required', Rule::in($this->catalog->types($service))],
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')],
            'city_id' => ['required', 'integer', Rule::exists('cities', 'id')],
            'title' => ['required', 'string', 'max:80', 'regex:'.self::TITLE_PATTERN],
            'description' => ['nullable', 'string', 'max:5000'],
            'needed_by' => ['required', 'date', 'after_or_equal:today', self::workDayRule()],
            'rush_reason' => ['nullable', 'string', 'max:1000'],
            'delivery_channel' => ['nullable', Rule::in($channels)],
            'delivery_contact' => ['nullable', 'string', 'max:190'],
            'answers' => ['nullable', 'array'],
        ], [
            'title.regex' => __('media.err.nameEnglish'),
        ], [
            'program_id' => __('media.f.program'),
            'city_id' => __('media.f.city'),
            'title' => __('media.f.name'),
            'needed_by' => __('media.f.deadline'),
        ])->validate();

        $answers = $this->catalog->clean($service, $type, (array) ($data['answers'] ?? []));
        $errors = [];

        foreach ($this->catalog->missing($service, $type, $answers) as $key => $message) {
            $errors['answers.'.$key] = $message;
        }

        if ($this->isRush($valid['needed_by'], $service, $type, $answers) && trim((string) ($valid['rush_reason'] ?? '')) === '') {
            $errors['rush_reason'] = __('media.err.reason');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'service' => $service,
            'type' => $type,
            'program_id' => (int) $valid['program_id'],
            'city_id' => (int) $valid['city_id'],
            // The archive prefix is added in front automatically; keep the short
            // name to safe file-name characters so the archive name stays valid.
            'title' => trim($valid['title']),
            'description' => $this->cleanNote($valid['description'] ?? null),
            'needed_by' => $valid['needed_by'],
            'rush_reason' => $this->isRush($valid['needed_by'], $service, $type, $answers) ? $this->cleanNote($valid['rush_reason'] ?? null) : null,
            'delivery_channel' => $valid['delivery_channel'] ?? null,
            'delivery_contact' => $this->cleanNote($valid['delivery_contact'] ?? null),
            'answers' => $answers,
        ];
    }

    /** No delivery on a day the media team does not work (Saturday, Sunday). */
    public static function workDayRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            try {
                $day = \Illuminate\Support\Carbon::parse((string) $value);
            } catch (\Throwable) {
                return;   // the "date" rule reports it
            }
            if (! app(\App\Support\MediaRequests\WorkCalendar::class)->isWorkDay($day)) {
                $fail(__('media.err.weekend'));
            }
        };
    }

    /**
     * Earlier than the team can do it in working time (see WorkCalendar), so it
     * needs a reason. Before the type is known, a plain "less than two days" rule.
     */
    public function isRush(?string $date, ?string $service = null, ?string $type = null, array $answers = []): bool
    {
        if ($date === null || $date === '') {
            return false;
        }

        $earliest = app(\App\Support\MediaRequests\WorkCalendar::class)->earliest($service, $type, $answers);
        if ($earliest !== null) {
            return \Illuminate\Support\Carbon::parse($date)->startOfDay()->lt($earliest);
        }

        return today()->diffInDays(\Illuminate\Support\Carbon::parse($date), false) < (int) config('media-requests.rush_days', 2);
    }

    private function teamMember(int $employeeId): Employee
    {
        $employee = $this->team->members()->firstWhere('id', $employeeId);

        if ($employee === null) {
            throw ValidationException::withMessages(['assigneeId' => __('media.err.assignee')]);
        }

        return $employee;
    }

    private function validLink(string $link, string $field = 'link'): string
    {
        $link = trim($link);

        Validator::make([$field => $link], [$field => ['required', 'url:http,https', 'max:500']], [
            'required' => __('media.err.link'),
            'url' => __('media.err.link'),
        ])->validate();

        return $link;
    }

    /** Extensions the team may send as a draft or a result. */
    public const RESULT_FILES = 'jpg,jpeg,png,webp,gif,svg,pdf,mp4,mov,zip,ai,psd,eps,doc,docx,ppt,pptx';

    /**
     * A draft or a result is a link, files, or both; at least one. The files are kept in the
     * "results" collection with the kind and the version (revisions + 1).
     *
     * @return array{0: ?string, 1: array<int, string>} the link (null when none) and the file names
     */
    private function linkOrFiles(MediaRequest $r, ?string $link, array $files, string $kind): array
    {
        $link = trim((string) $link);
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile));

        Validator::make(['link' => $link, 'files' => $files], [
            'link' => ['nullable', 'url:http,https', 'max:500'],
            'files' => ['array', 'max:10'],
            'files.*' => ['file', 'max:51200', 'mimes:'.self::RESULT_FILES],
        ], ['url' => __('media.err.link')], ['files' => __('media.fm.files')])->validate();

        if ($link === '' && $files === []) {
            throw ValidationException::withMessages(['link' => __('media.err.linkOrFile')]);
        }

        $names = [];
        foreach ($files as $file) {
            $names[] = $r->addMedia($file)
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->withCustomProperties(['kind' => $kind, 'version' => $r->revisions + 1, 'folder' => MediaFolders::folderFor($r)])
                ->toMediaCollection('results')->file_name;
        }

        return [$link === '' ? null : $link, $names];
    }

    private function requiredNote(?string $note): string
    {
        $note = $this->cleanNote($note);

        if ($note === null) {
            throw ValidationException::withMessages(['note' => __('media.err.reason')]);
        }

        return $note;
    }

    private function cleanNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 2000);
    }

    /** @return array<string, array{0: mixed, 1: mixed}> field => [before, after] */
    private function changedFields(array $before, MediaRequest $r): array
    {
        $changes = [];

        foreach (self::EDITABLE as $field) {
            $old = $before[$field] ?? null;
            $new = $r->{$field};
            $old = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old;
            $new = $new instanceof \DateTimeInterface ? $new->format('Y-m-d') : $new;

            if ((string) $old !== (string) $new) {
                $changes[$field] = [$old, $new];
            }
        }

        $oldAnswers = (array) ($before['answers'] ?? []);
        $newAnswers = (array) ($r->answers ?? []);

        foreach (array_unique([...array_keys($oldAnswers), ...array_keys($newAnswers)]) as $key) {
            if (json_encode($oldAnswers[$key] ?? null) !== json_encode($newAnswers[$key] ?? null)) {
                $changes['answers.'.$key] = [$oldAnswers[$key] ?? null, $newAnswers[$key] ?? null];
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string> stored file names
     */
    private function addFiles(MediaRequest $r, array $files): array
    {
        $names = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $media = $r->addMedia($file)
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->withCustomProperties(['folder' => MediaFolders::folderFor($r)])
                ->toMediaCollection('attachments');
            $names[] = $media->file_name;
        }

        return $names;
    }

    // ------------------------------------------------------------------
    // Moving, logging, notifying
    // ------------------------------------------------------------------

    private function move(MediaRequest $r, string $to): void
    {
        $r->status = $to;
        $r->status_changed_at = now();
    }

    /** Save, log, then tell the next owner — after the transaction, so nobody is told about a change that was rolled back. */
    private function commit(MediaRequest $r, User $actor, string $event, ?string $from, array $properties = [], array $params = []): MediaRequest
    {
        DB::transaction(fn () => $this->log($r, $actor, $event, $from, $properties, save: true));

        $this->notifier->event($r, $event, $actor, $params);

        return $r;
    }

    private function log(MediaRequest $r, User $actor, string $event, ?string $from, array $properties = [], bool $save = false): void
    {
        if ($save) {
            $r->save();
        }

        $properties = array_filter(
            ['from' => $from, 'to' => $r->status, ...$properties],
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );

        $this->logger->log($event, $r, $actor, $properties, MediaRequest::LOG_NAME);
    }

    /** References are numbered per service and year; two people filing at once retry with the next number. */
    private function createWithReference(string $service, callable $create): MediaRequest
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $create($this->naming->nextReference($service)));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }
}
