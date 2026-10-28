<?php

namespace App\Models;

use App\Enums\MediaRequestStatus;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property string $status
 * @property Carbon|null $status_changed_at
 * @property array|null $answers
 */
class MediaRequest extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const LOG_NAME = 'media-requests';

    protected $fillable = [
        'reference', 'requester_id', 'program_id', 'city_id', 'service', 'type', 'item_code',
        'title', 'description', 'answers', 'pack_files', 'needed_by', 'rush_reason',
        'delivery_channel', 'delivery_contact', 'status', 'status_changed_at', 'return_to', 'held_by',
        'needs_program_approval', 'program_approver_id', 'program_approved_at',
        'media_approver_id', 'media_approved_at', 'assignee_id', 'priority', 'planned_for',
        'internal_ok', 'revisions', 'draft_link', 'result_link', 'archive_link', 'archive_name',
        'last_nudged_at', 'last_reminded_on', 'delivered_at',
    ];

    protected $casts = [
        'answers' => 'array',
        'pack_files' => 'array',
        'needed_by' => 'date',
        'planned_for' => 'date',
        'status_changed_at' => 'datetime',
        'program_approved_at' => 'datetime',
        'media_approved_at' => 'datetime',
        'last_nudged_at' => 'datetime',
        'delivered_at' => 'datetime',
        'needs_program_approval' => 'boolean',
        'internal_ok' => 'boolean',
        'revisions' => 'integer',
    ];

    public function registerMediaCollections(): void
    {
        // Briefs often hold names and photos of beneficiaries, so files stay on
        // the private disk and are read back through MediaRequestFileController,
        // which runs MediaRequestPolicy::view first.
        $this->addMediaCollection('attachments')->useDisk('local');
        // What the team sends: drafts and versions of the result (images, PDFs, videos…), each with its version.
        $this->addMediaCollection('results')->useDisk('local');
    }

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_id');
    }

    public function programApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'program_approver_id');
    }

    public function mediaApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'media_approver_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(MediaRequestComment::class)->oldest();
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->where('log_name', self::LOG_NAME)->latest('id');
    }

    // ------------------------------------------------------------------
    // State helpers
    // ------------------------------------------------------------------

    /** The team field this request belongs to: design, photo, video or montage. */
    public function specialty(): ?string
    {
        return app(\App\Support\MediaRequests\MediaTeam::class)->specialty($this->service, $this->type);
    }

    /** Delivered before (early), on (onTime) or after (late) its needed-by day; null while open. */
    public function deliveryTiming(): ?string
    {
        if ($this->status !== MediaRequestStatus::Delivered || ! $this->delivered_at || ! $this->needed_by) {
            return null;
        }

        $done = $this->delivered_at->copy()->startOfDay();
        $due = $this->needed_by->copy()->startOfDay();

        return $done->lt($due) ? 'early' : ($done->gt($due) ? 'late' : 'onTime');
    }

    /** Delivered on the very day it was asked for (Damascus time): the strongest badge. */
    public function sameDay(): bool
    {
        if ($this->status !== MediaRequestStatus::Delivered || ! $this->delivered_at || ! $this->created_at) {
            return false;
        }
        $tz = (string) config('media-requests.work.timezone', 'Asia/Damascus');

        return $this->created_at->copy()->setTimezone($tz)->isSameDay($this->delivered_at->copy()->setTimezone($tz));
    }

    /** Working days delivered ahead of the needed-by day (0 unless early). */
    public function daysEarly(): int
    {
        if ($this->deliveryTiming() !== 'early') {
            return 0;
        }

        $calendar = app(\App\Support\MediaRequests\WorkCalendar::class);
        $n = 0;
        for ($d = $this->delivered_at->copy()->startOfDay()->addDay(); $d->lte($this->needed_by->copy()->startOfDay()); $d->addDay()) {
            $n += $calendar->isWorkDay($d) ? 1 : 0;
        }

        return max(1, $n);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, MediaRequestStatus::open(), true);
    }

    public function isPack(): bool
    {
        return $this->service === '000';
    }

    public function needsProduction(): bool
    {
        return ($this->service === '001' && in_array($this->type, ['print', 'production'], true)) || $this->wantsPrint();
    }

    /**
     * Photography (shoots and photo delivery) and video filming end with a link: the media
     * manager checks it and it is delivered, without a requester review. Editing keeps the full review.
     */
    public function shortPath(): bool
    {
        return $this->service === '002' || ($this->service === '003' && $this->type === 'shoot');
    }

    /** A new design the requester also wants printed, in this same request. */
    public function wantsPrint(): bool
    {
        return $this->service === '001' && $this->type === 'new' && $this->answer('alsoPrint') === 'yes';
    }

    /** Printing with an unknown budget: the media team sends a price quote first. */
    public function needsQuote(): bool
    {
        return $this->wantsPrint() && $this->answer('budget') === 'quote';
    }

    /** requester | program_manager | media_manager | team | assignee | null */
    public function owner(): ?string
    {
        if ($this->status === MediaRequestStatus::OnHold) {
            return $this->held_by;
        }

        if (in_array($this->status, [MediaRequestStatus::Delivery], true) && ! $this->assignee_id) {
            return 'team';
        }

        return MediaRequestStatus::OWNERS[$this->status] ?? null;
    }

    /** Who holds the request now, in words: a person's name, or the role when it is a group. */
    public function ownerName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($owner = $this->owner()) {
            'requester' => $this->requester?->nameForLocale($locale) ?? '',
            'assignee' => $this->assignee?->nameForLocale($locale) ?? '',
            'program_manager' => app(MediaTeam::class)->programManagers($this->program)
                ->map(fn (Employee $e): string => $e->nameForLocale($locale))
                ->implode('، ') ?: __('media.owner.program_manager', [], $locale),
            null => '',
            default => __('media.owner.'.$owner, [], $locale),
        };
    }

    /** The status the tracker counts from while the request waits for an answer or is paused. */
    public function effectiveStatus(): string
    {
        return in_array($this->status, [MediaRequestStatus::NeedsInfo, MediaRequestStatus::OnHold], true)
            ? ($this->return_to ?: MediaRequestStatus::MediaApproval)
            : $this->status;
    }

    /** Tracker stages that apply to this request: submitted, pm, mm, claim, progress, int, req, production, delivery. */
    public function steps(): array
    {
        if ($this->isPack()) {
            return ['submitted', 'delivery'];
        }

        return array_values(array_filter([
            'submitted',
            $this->needs_program_approval ? 'pm' : null,
            'mm', 'claim', 'progress', 'int',
            $this->shortPath() ? null : 'req',
            $this->needsProduction() ? 'production' : null,
            'delivery',
        ]));
    }

    /** Index into steps() of the stage the request is in; past the end once delivered, -1 when stopped. */
    public function currentStep(): int
    {
        if ($this->status === MediaRequestStatus::Delivered) {
            return count($this->steps());
        }

        $step = [
            MediaRequestStatus::ProgramApproval => 'pm',
            MediaRequestStatus::MediaApproval => 'mm',
            MediaRequestStatus::ToClaim => 'claim',
            MediaRequestStatus::Assigned => 'progress',
            MediaRequestStatus::InProgress => 'progress',
            MediaRequestStatus::InternalReview => 'int',
            MediaRequestStatus::RequesterReview => 'req',
            MediaRequestStatus::Production => 'production',
            MediaRequestStatus::Delivery => 'delivery',
        ][$this->effectiveStatus()] ?? null;

        $index = $step === null ? false : array_search($step, $this->steps(), true);

        return $index === false ? -1 : $index;
    }

    /** Board column: approval, claim, work, int, req, finish, done. Paused requests stay where they paused. */
    public function boardColumn(): ?string
    {
        return match ($this->effectiveStatus()) {
            MediaRequestStatus::ProgramApproval, MediaRequestStatus::MediaApproval => 'approval',
            MediaRequestStatus::ToClaim => 'claim',
            MediaRequestStatus::Assigned, MediaRequestStatus::InProgress => 'work',
            MediaRequestStatus::InternalReview => 'int',
            MediaRequestStatus::RequesterReview => 'req',
            MediaRequestStatus::Production, MediaRequestStatus::Delivery => 'finish',
            MediaRequestStatus::Delivered => 'done',
            default => null,
        };
    }

    public function isLate(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        if (in_array($this->status, MediaRequestStatus::working(), true)) {
            $due = $this->planned_for ?? $this->needed_by;

            return $due !== null && $due->lt(today());
        }

        return $this->status_changed_at !== null
            && $this->status_changed_at->lt(now()->subHours((int) config('media-requests.late_after_hours', 24)));
    }

    public function nudgedRecently(): bool
    {
        return $this->last_nudged_at !== null
            && $this->last_nudged_at->gt(now()->subHours((int) config('media-requests.nudge_every_hours', 24)));
    }

    public function answer(string $key, mixed $default = null): mixed
    {
        return data_get($this->answers ?? [], $key, $default);
    }

    public function displayName(): string
    {
        return app(\App\Support\MediaRequests\MediaNaming::class)->displayName($this);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', MediaRequestStatus::open());
    }

    /**
     * What belongs on someone's own pages: what they asked for, their
     * programs' requests, and for the Media team what is on their plate.
     */
    public function scopeRelevantTo(Builder $query, User $user): Builder
    {
        $team = app(MediaTeam::class);
        $programIds = $team->managedProgramIds($user->profile);

        return $query->where(function (Builder $q) use ($user, $programIds, $team): void {
            $q->where('requester_id', $user->employee_id);

            if ($programIds !== []) {
                $q->orWhereIn('program_id', $programIds);
            }

            if ($team->isMember($user->profile)) {
                $q->orWhere('assignee_id', $user->employee_id)
                    ->orWhereIn('status', [MediaRequestStatus::ToClaim, MediaRequestStatus::Delivery]);
            }

            if ($team->canActAsMediaManager($user)) {
                $q->orWhereIn('status', [MediaRequestStatus::MediaApproval, MediaRequestStatus::InternalReview, MediaRequestStatus::OnHold]);
            }
        });
    }

    /**
     * Requests the given employee may see: their own, every request of a
     * program they manage, and everything when they are on the Media team.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $team = app(MediaTeam::class);

        if ($team->isAdministrator($user) || $team->isMember($user->profile)) {
            return $query;
        }

        $employeeId = $user->employee_id;
        $programIds = $team->managedProgramIds($user->profile);

        return $query->where(function (Builder $q) use ($employeeId, $programIds): void {
            $q->where('requester_id', $employeeId);

            if ($programIds !== []) {
                $q->orWhereIn('program_id', $programIds);
            }
        });
    }
}
