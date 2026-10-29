<?php

namespace App\Services;

use App\Enums\MediaRequestStatus as S;
use App\Models\Employee;
use App\Models\MediaRequest;
use App\Models\User;
use App\Notifications\MediaRequestUpdated;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the next owner it is their turn, and keeps the requester informed so
 * nothing at the end comes as a surprise. The person who acted is never
 * notified about their own action.
 *
 * Delivery is the app's database channel (the bell). HC-Connect can be added
 * as a second channel on MediaRequestUpdated once its API is known.
 */
class MediaRequestNotifier
{
    public function __construct(private MediaTeam $team) {}

    public function event(MediaRequest $r, string $event, ?User $actor, array $params = []): void
    {
        $recipients = match ($event) {
            'submitted', 'replied', 'resumed', 'nudged', 'edited' => $this->owners($r),
            'packAsked' => $this->team->accounts($this->team->members()),
            'pmApproved', 'mmApproved' => $this->owners($r)->merge($this->requester($r)),
            'assigned' => $this->owners($r)->merge($this->requester($r)),
            // A result to review goes to the requester and the program manager: either may approve it.
            'intOk', 'sentReq' => $this->requester($r)->merge($this->team->accounts($this->team->programManagers($r->program))),
            'filedFor', 'claimed', 'started', 'draft', 'ready', 'delivered', 'packDone',
            'info', 'rejected', 'hold' => $this->requester($r),
            'sentInt' => $this->owners($r),
            'intBack', 'changes', 'reqOk' => $this->assignee($r),
            'cancelled' => $this->assignee($r)->merge($r->status === S::Cancelled ? $this->team->accounts($this->team->managers()) : collect()),
            'comment' => $this->conversationPartners($r, $actor),
            // Deadline reminders (media:remind): whoever holds it; when it is late, also the assignee and the media manager.
            'dueSoon', 'dueToday' => $this->owners($r),
            'overdue' => $this->owners($r)->merge($this->assignee($r))->merge($this->team->accounts($this->team->managers())),
            default => collect(),
        };

        $recipients = $recipients
            ->filter(fn ($u) => $u instanceof User)
            ->reject(fn (User $u) => $actor !== null && $u->id === $actor->id)
            ->unique('id')
            ->values();

        // Delivered: an email too, to the requester and their program manager, with the details.
        // A mail server that is down never stops the delivery itself.
        if ($event === 'delivered') {
            $mail = $this->requester($r)->merge($this->team->accounts($this->team->programManagers($r->program)))
                ->filter(fn ($u) => $u instanceof User && filled($u->email))->unique('id')->values();
            if ($mail->isNotEmpty()) {
                rescue(fn () => Notification::send($mail, new \App\Notifications\MediaRequestDelivered($r)), report: true);
            }
        }

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new MediaRequestUpdated($r, $event, $actor?->profile, $params));
    }

    /** Whoever holds the request now. */
    public function owners(MediaRequest $r): Collection
    {
        return match ($r->owner()) {
            'requester' => $this->requester($r),
            'program_manager' => $this->team->accounts($this->team->programManagers($r->program)),
            'media_manager' => $this->team->accounts($this->team->managers()),
            'team' => $this->team->accounts($this->team->members()),
            'assignee' => $this->assignee($r),
            default => collect(),
        };
    }

    private function requester(MediaRequest $r): Collection
    {
        return $this->team->accounts(collect([$r->requester]));
    }

    private function assignee(MediaRequest $r): Collection
    {
        return $this->team->accounts(collect([$r->assignee]));
    }

    /** A message from the requester goes to the team side; one from the team goes to the requester. */
    private function conversationPartners(MediaRequest $r, ?User $actor): Collection
    {
        if ($actor && $actor->employee_id === $r->requester_id) {
            return $r->assignee_id
                ? $this->assignee($r)
                : $this->team->accounts($this->team->managers());
        }

        return $this->requester($r)->merge($this->assignee($r));
    }

    /** @param  Collection<int, Employee>  $employees */
    public function accountsOf(Collection $employees): Collection
    {
        return $this->team->accounts($employees);
    }
}
