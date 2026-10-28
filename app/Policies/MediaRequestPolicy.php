<?php

namespace App\Policies;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;
use App\Models\User;
use App\Support\MediaRequests\MediaTeam;

/**
 * Who may do what on one media request. Permissions (media-requests.*) only
 * open the screens; these checks bind each step to its owner:
 *
 *  program manager  approve / ask / reject while it waits for the program
 *  media manager    approve, assign, check the work, hold, reject
 *  team member      take a released request; the assignee does the work
 *  requester        edit before approval, answer questions, review the result
 */
class MediaRequestPolicy
{
    public function __construct(private MediaTeam $team) {}

    /** Anyone with an employee profile may ask the Media team for something. */
    public function create(User $user): bool
    {
        return $user->employee_id !== null;
    }

    public function view(User $user, MediaRequest $r): bool
    {
        return $this->isRequester($user, $r)
            || $this->team->isAdministrator($user)
            || $this->team->isMember($user->profile)
            || $this->team->managesProgram($user->profile, $r->program_id);
    }

    /** Direct edits until the media manager accepts it, or while it waits for missing information. */
    public function update(User $user, MediaRequest $r): bool
    {
        return $this->isRequester($user, $r)
            && ! $r->isPack()
            && in_array($r->status, S::editable(), true);
    }

    public function comment(User $user, MediaRequest $r): bool
    {
        return $r->isOpen() && $this->view($user, $r);
    }

    public function attach(User $user, MediaRequest $r): bool
    {
        return $r->isOpen() && ($this->isRequester($user, $r) || $this->team->isMember($user->profile) || $this->mm($user));
    }

    public function approveProgram(User $user, MediaRequest $r): bool
    {
        return $r->status === S::ProgramApproval && $this->pm($user, $r);
    }

    public function approveMedia(User $user, MediaRequest $r): bool
    {
        return $r->status === S::MediaApproval && $this->mm($user);
    }

    /** Give it to a team member: after approval, or to re-assign work in progress. */
    public function assign(User $user, MediaRequest $r): bool
    {
        return $this->mm($user) && in_array($r->status, [S::ToClaim, S::Assigned, S::InProgress], true);
    }

    public function claim(User $user, MediaRequest $r): bool
    {
        // A shoot is taken by the team of the place; someone else goes only when the media manager sends them.
        return $r->status === S::ToClaim && $this->team->isMember($user->profile) && $this->team->coversArea($user->profile, $r);
    }

    public function start(User $user, MediaRequest $r): bool
    {
        return $r->status === S::Assigned && $this->doer($user, $r);
    }

    /** Share a draft or send the result. */
    public function work(User $user, MediaRequest $r): bool
    {
        return $r->status === S::InProgress && $this->doer($user, $r);
    }

    public function reviewInternal(User $user, MediaRequest $r): bool
    {
        return $r->status === S::InternalReview && $this->mm($user);
    }

    public function reviewResult(User $user, MediaRequest $r): bool
    {
        // The requester or the program manager: either one approving, or asking for changes, is enough.
        return $r->status === S::RequesterReview && ($this->isRequester($user, $r) || $this->pm($user, $r));
    }

    public function markReady(User $user, MediaRequest $r): bool
    {
        return $r->status === S::Production && $this->doer($user, $r);
    }

    public function deliver(User $user, MediaRequest $r): bool
    {
        if ($r->status !== S::Delivery) {
            return false;
        }

        // A ready HC_PACK file can be sent by anyone on the team.
        return $this->doer($user, $r) || ($r->isPack() && $this->team->isMember($user->profile));
    }

    public function askInfo(User $user, MediaRequest $r): bool
    {
        return match ($r->status) {
            S::ProgramApproval => $this->pm($user, $r),
            S::MediaApproval, S::ToClaim => $this->mm($user),
            S::Assigned, S::InProgress => $this->doer($user, $r),
            default => false,
        };
    }

    public function reply(User $user, MediaRequest $r): bool
    {
        return $r->status === S::NeedsInfo && $this->isRequester($user, $r);
    }

    public function reject(User $user, MediaRequest $r): bool
    {
        return ($r->status === S::ProgramApproval && $this->pm($user, $r))
            || ($r->status === S::MediaApproval && $this->mm($user));
    }

    public function hold(User $user, MediaRequest $r): bool
    {
        if ($r->status === S::ProgramApproval) {
            return $this->pm($user, $r);   // the program manager postpones at their own approval
        }

        return $this->mm($user) && $r->isOpen()
            && ! in_array($r->status, [S::OnHold, S::NeedsInfo], true);
    }

    public function resume(User $user, MediaRequest $r): bool
    {
        return $r->status === S::OnHold
            && ($r->held_by === 'program_manager' ? $this->pm($user, $r) : $this->mm($user));
    }

    public function cancel(User $user, MediaRequest $r): bool
    {
        if (! $r->isOpen() || $r->status === S::Delivery) {
            return false;
        }

        return $this->isRequester($user, $r) || $this->mm($user);
    }

    /** Remind whoever holds it now — once a day, and never yourself. */
    public function nudge(User $user, MediaRequest $r): bool
    {
        if (! $r->isOpen() || $r->nudgedRecently() || $this->isOwner($user, $r)) {
            return false;
        }

        return $this->isRequester($user, $r)
            || $this->team->managesProgram($user->profile, $r->program_id)
            || $this->mm($user);
    }

    /** Is it this person's turn? Drives "needs your action" everywhere. */
    public function isOwner(User $user, MediaRequest $r): bool
    {
        // The result waits for the requester and the program manager alike.
        if ($r->status === S::RequesterReview && $this->pm($user, $r)) {
            return true;
        }

        return match ($r->owner()) {
            'requester' => $this->isRequester($user, $r),
            'program_manager' => $this->pm($user, $r),
            'media_manager' => $this->mm($user),
            'team' => $this->team->isMember($user->profile),
            'assignee' => $r->assignee_id !== null && $r->assignee_id === $user->employee_id,
            default => false,
        };
    }

    // ------------------------------------------------------------------

    private function isRequester(User $user, MediaRequest $r): bool
    {
        return $user->employee_id !== null && $user->employee_id === $r->requester_id;
    }

    private function pm(User $user, MediaRequest $r): bool
    {
        return $this->team->managesProgram($user->profile, $r->program_id) || $user->myRole() === 'super-admin';
    }

    private function mm(User $user): bool
    {
        return $this->team->canActAsMediaManager($user);
    }

    /** The assignee, or the media manager acting for them. */
    private function doer(User $user, MediaRequest $r): bool
    {
        return ($r->assignee_id !== null && $r->assignee_id === $user->employee_id) || $this->mm($user);
    }
}
