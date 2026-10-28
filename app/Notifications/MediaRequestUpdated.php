<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Models\MediaRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One notification type for every media-request event, in the same shape as
 * the leave notifications (bilingual title and body, a link that opens the
 * request). The body names who did what, so the bell reads like the log.
 */
class MediaRequestUpdated extends Notification
{
    use Queueable;

    public function __construct(
        public MediaRequest $mediaRequest,
        public string $event,
        public ?Employee $actor = null,
        public array $params = [],
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $r = $this->mediaRequest;
        $name = $r->displayName();

        $body = fn (string $locale): string => trim(
            ($this->actor ? $this->actor->nameForLocale($locale).' ' : '')
            .__('media.ev.'.$this->event, self::localizeParams($this->params, $locale), $locale),
        ).' — '.$r->reference.' · '.$name;

        return [
            'title' => [
                'en' => __('media.nt.title', [], 'en'),
                'ar' => __('media.nt.title', [], 'ar'),
            ],
            'body' => [
                'en' => $body('en'),
                'ar' => $body('ar'),
            ],
            'url' => route('media-requests.show', $r, false),   // a path, so it opens on whatever address the app runs at
            'media_request_id' => $r->id,
            'event' => $this->event,
        ];
    }

    /**
     * Event parameters are stored as ids and keys (to = employee id,
     * prio = high|normal|low, n = number) and turned into words per language.
     *
     * @return array<string, string>
     */
    public static function localizeParams(array $params, string $locale): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            $out[$key] = match ($key) {
                'to' => $value ? (Employee::find($value)?->nameForLocale($locale) ?? '') : '',
                'prio' => $value ? __('media.prio.'.$value, [], $locale) : '',
                'role' => $value ? __('media.owner.'.$value, [], $locale) : '',
                default => (string) $value,
            };
        }

        return $out;
    }
}
