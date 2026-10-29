<?php

namespace App\Notifications;

use App\Models\MediaRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email when a request is delivered, to the requester and their program manager:
 * what it was, for which program, when it was asked and delivered, who did it, and a
 * button that opens it in the app. The files are seen and downloaded in the app only;
 * nothing is attached.
 */
class MediaRequestDelivered extends Notification
{
    use Queueable;

    public function __construct(public MediaRequest $mediaRequest) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->mediaRequest->loadMissing(['requester', 'assignee', 'program', 'city', 'mediaApprover']);

        return (new MailMessage)
            ->subject(__('media.mail.subject', ['ref' => $r->reference, 'name' => $r->displayName()], 'ar').' | '.__('media.mail.subject', ['ref' => $r->reference, 'name' => $r->displayName()], 'en'))
            ->view('mail.media-delivered', ['r' => $r, 'to' => $notifiable, 'url' => route('media-requests.show', $r)]);
    }
}
