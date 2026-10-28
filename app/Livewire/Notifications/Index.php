<?php

namespace App\Livewire\Notifications;

use Livewire\Component;

/** The bell: the same database notifications the HR app shows, for the signed-in user only. */
class Index extends Component
{
    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    /** People who only ask for work have the home screen only. */
    public function mount(): void
    {
        if (app(\App\Support\MediaRequests\MediaTeam::class)->isRequesterOnly(auth()->user())) {
            $this->redirectRoute('media-requests.home');
        }
    }

    public function render()
    {
        return view('livewire.notifications.index', [
            'items' => auth()->user()->notifications()->limit(100)->get(),
        ]);
    }
}
