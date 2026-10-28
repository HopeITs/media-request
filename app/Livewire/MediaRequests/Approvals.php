<?php

namespace App\Livewire\MediaRequests;

use App\Enums\MediaRequestStatus as S;
use App\Models\MediaRequest;
use App\Support\MediaRequests\MediaTeam;
use Livewire\Component;

/** Requests waiting for the program manager. The decision itself is taken on the request page. */
class Approvals extends Component
{
    public function render()
    {
        $team = app(MediaTeam::class);
        $user = auth()->user();
        $programIds = $team->managedProgramIds($user->profile);
        $everything = $user->myRole() === 'super-admin';

        $requests = MediaRequest::query()
            ->with(['requester', 'program', 'city'])
            ->where('status', S::ProgramApproval)
            ->when(! $everything, fn ($q) => $q->whereIn('program_id', $programIds ?: [0]))
            ->orderBy('needed_by')
            ->get();

        return view('livewire.media-requests.approvals', ['requests' => $requests]);
    }
}
